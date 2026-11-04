<?php

namespace LiveNet\LiveNetAuth\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LiveNet\LiveNetAuth\Http\Middleware\EndRevokedSessions;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Receives Authentik's back-channel logout notice (OpenID Connect Back-Channel Logout 1.0): a signed
 * "logout_token" saying a person's login ended. The time is remembered, and EndRevokedSessions ends
 * any panel session that began earlier.
 */
class BackchannelLogout
{
    private const EVENT = 'http://schemas.openid.net/event/backchannel-logout';

    public function __invoke(Request $request): Response
    {
        if (!config('livenet-auth.backchannel_logout')) {
            abort(404);
        }

        try {
            $claims = $this->verify((string) $request->input('logout_token'));
        } catch (Throwable $exception) {
            Log::warning('livenet-auth: back-channel logout rejected: ' . $exception->getMessage());

            return response()->json(['error' => 'invalid_request', 'error_description' => $exception->getMessage()], 400);
        }

        // The token's own time (Authentik's clock, like the login time kept in the session).
        $key = EndRevokedSessions::cacheKey($claims['sub']);

        if ((int) $claims['iat'] > (int) Cache::get($key, 0)) {
            Cache::put($key, (int) $claims['iat'], now()->addDays(2));
        }

        Log::info('livenet-auth: back-channel logout accepted for ' . substr(hash('sha256', $claims['sub']), 0, 10));

        return response('', 200);
    }

    /** @return array<string, mixed> */
    private function verify(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new RuntimeException('not a JWT');
        }

        $header = json_decode((string) self::b64($parts[0]), true);
        $claims = json_decode((string) self::b64($parts[1]), true);
        $signature = self::b64($parts[2]);

        if (!is_array($header) || !is_array($claims) || $signature === false) {
            throw new RuntimeException('malformed token');
        }

        $signed = $parts[0] . '.' . $parts[1];
        $valid = false;

        if (($header['alg'] ?? '') === 'RS256') {
            $valid = $this->verifyRsa($header['kid'] ?? null, $signed, $signature);
        } elseif (($header['alg'] ?? '') === 'HS256') {
            // Authentik signs with the client secret when the provider has no signing key.
            $secret = self::setting('client_secret');
            $valid = $secret !== '' && hash_equals(hash_hmac('sha256', $signed, $secret, true), $signature);
        } else {
            throw new RuntimeException('unsupported signing algorithm');
        }

        if (!$valid) {
            throw new RuntimeException('bad signature');
        }

        $clientId = self::setting('client_id');

        if ($clientId === '' || !in_array($clientId, (array) ($claims['aud'] ?? []), true)) {
            throw new RuntimeException('wrong audience');
        }

        if (($claims['iss'] ?? null) !== self::issuer()) {
            throw new RuntimeException('wrong issuer');
        }

        if (!isset($claims['events'][self::EVENT])) {
            throw new RuntimeException('not a logout event');
        }

        if (array_key_exists('nonce', $claims)) {
            throw new RuntimeException('logout tokens must not have a nonce');
        }

        if (!isset($claims['sub']) || !is_string($claims['sub']) || $claims['sub'] === '') {
            throw new RuntimeException('no subject');
        }

        $iat = $claims['iat'] ?? 0;

        if (!is_numeric($iat) || $iat < time() - 86400 || $iat > time() + 600) {
            throw new RuntimeException('token is too old or from the future');
        }

        if (isset($claims['exp']) && $claims['exp'] < time()) {
            throw new RuntimeException('token expired');
        }

        return $claims;
    }

    private function verifyRsa(?string $kid, string $signed, string $signature): bool
    {
        foreach ([false, true] as $refresh) {
            foreach ($this->keys($refresh) as $jwk) {
                if (($jwk['kty'] ?? '') !== 'RSA' || ($kid !== null && ($jwk['kid'] ?? null) !== $kid)) {
                    continue;
                }

                $n = self::b64((string) ($jwk['n'] ?? ''));
                $e = self::b64((string) ($jwk['e'] ?? ''));

                if ($n === false || $e === false) {
                    continue;
                }

                $key = openssl_pkey_get_public(self::pem($n, $e));

                return $key !== false && openssl_verify($signed, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
            }
        }

        return false;
    }

    /** @return array<int, array<string, mixed>> */
    private function keys(bool $refresh): array
    {
        $cacheKey = 'livenet-auth.jwks';

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 3600, function (): array {
            $discovery = Http::timeout(5)->get(self::issuer() . '.well-known/openid-configuration')->throw()->json();
            $uri = $discovery['jwks_uri'] ?? null;

            if (!is_string($uri) || $uri === '') {
                throw new RuntimeException('no jwks_uri');
            }

            return (array) (Http::timeout(5)->get($uri)->throw()->json('keys') ?? []);
        });
    }

    private static function issuer(): string
    {
        $issuer = (string) config('livenet-auth.backchannel_issuer');

        if ($issuer === '') {
            $issuer = (string) preg_replace('#end-session/?$#', '', (string) config('livenet-auth.logout_end_session_url'));
        }

        return rtrim($issuer, '/') . '/';
    }

    /** The provider's client id or secret: this plugin's own setting first, then the panel's OAuth settings. */
    private static function setting(string $name): string
    {
        $provider = (string) config('livenet-auth.provider');

        return (string) (config('livenet-auth.backchannel_' . $name)
            ?: config("services.{$provider}.{$name}")
            ?: env('OAUTH_' . strtoupper($provider) . '_' . strtoupper($name), ''));
    }

    private static function b64(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }

    /** Builds a PEM public key from an RSA key's modulus and exponent. */
    private static function pem(string $n, string $e): string
    {
        $rsaKey = self::der(0x30, self::derInt($n) . self::derInt($e));
        $algorithm = self::der(0x30, hex2bin('06092a864886f70d010101') . "\x05\x00");
        $spki = self::der(0x30, $algorithm . self::der(0x03, "\x00" . $rsaKey));

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function der(int $tag, string $content): string
    {
        $length = strlen($content);

        if ($length < 128) {
            $encoded = chr($length);
        } else {
            $bytes = ltrim(pack('N', $length), "\x00");
            $encoded = chr(0x80 | strlen($bytes)) . $bytes;
        }

        return chr($tag) . $encoded . $content;
    }

    private static function derInt(string $binary): string
    {
        $binary = ltrim($binary, "\x00");

        if ($binary === '' || ord($binary[0]) > 0x7f) {
            $binary = "\x00" . $binary;
        }

        return self::der(0x02, $binary);
    }
}
