<?php

namespace LiveNet\LiveNetAuth\Support;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Finds panel accounts whose LiveNet (Authentik) account no longer exists.
 *
 * Panel accounts are matched to Authentik by email. If someone's Authentik account is deleted but
 * their panel account stays, a stranger who later signs up with that email would be linked to the old
 * account and could reach its servers. Uses a read-only Authentik token, and never acts on an answer
 * it could not fully read.
 */
class Orphans
{
    /**
     * @return array<int, array{user: User, owns: bool}>
     *
     * @throws RuntimeException when Authentik cannot give a clear answer (nothing should be changed then)
     */
    public static function scan(): array
    {
        $base = rtrim((string) config('livenet-auth.authentik_url'), '/');
        $token = (string) config('livenet-auth.authentik_api_token');
        $provider = (string) config('livenet-auth.provider');

        if ($base === '' || $token === '') {
            throw new RuntimeException('Set the Authentik address and API token first (Admin -> Plugins -> LiveNet Auth -> Settings).');
        }

        [$emails, $usernames] = self::people($base, $token);

        $orphans = [];
        foreach (User::query()->get() as $user) {
            $links = is_array($user->oauth) ? $user->oauth : [];
            if (!isset($links[$provider]) || $user->isRootAdmin()) {
                continue;
            }

            $knownByEmail = in_array(mb_strtolower((string) $user->email), $emails, true);
            $knownByName = in_array(mb_strtolower((string) $user->username), $usernames, true);

            if (!$knownByEmail && !$knownByName) {
                // Pelican refuses to delete accounts that own servers, so those need a person to sort out.
                $orphans[] = ['user' => $user, 'owns' => $user->servers()->exists()];
            }
        }

        return $orphans;
    }

    /**
     * Every email and username Authentik has (including deactivated accounts).
     *
     * @return array{0: string[], 1: string[]}
     */
    private static function people(string $base, string $token): array
    {
        $emails = [];
        $usernames = [];
        $page = 1;

        for ($guard = 0; $guard < 200; $guard++) {
            $response = Http::withToken($token)->acceptJson()->timeout(15)
                ->get($base . '/api/v3/core/users/', ['page' => $page, 'page_size' => 200, 'include_groups' => 'false']);

            if (!$response->successful()) {
                throw new RuntimeException('Authentik answered HTTP ' . $response->status() . '.');
            }

            $data = $response->json();
            if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
                throw new RuntimeException('Authentik\'s answer was not in the expected format.');
            }

            foreach ($data['results'] as $person) {
                if (!empty($person['email'])) {
                    $emails[] = mb_strtolower((string) $person['email']);
                }
                if (!empty($person['username'])) {
                    $usernames[] = mb_strtolower((string) $person['username']);
                }
            }

            $next = (int) ($data['pagination']['next'] ?? 0);
            if ($next <= 0) {
                if (!$usernames) {
                    throw new RuntimeException('Authentik returned no users at all.');
                }

                return [array_values(array_unique($emails)), array_values(array_unique($usernames))];
            }

            $page = $next;
        }

        throw new RuntimeException('Too many pages from Authentik.');
    }
}
