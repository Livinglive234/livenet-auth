<?php

namespace LiveNet\LiveNetAuth\Http\Responses;

use Filament\Auth\Http\Responses\Contracts\LogoutResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;

/**
 * What happens right after the panel signs someone out.
 *
 * With the saved login token, ask Authentik to end that login and send the person back
 * to the panel's login page. Without it, go to Authentik's general logout page. Either
 * way the Authentik login ends too.
 *
 * The token comes from the panel's session, which the service provider copies into the
 * container just before the session is cleared (it is far too large to keep in a cookie).
 */
class LogoutToAuthentik implements LogoutResponse
{
    /** Container key the service provider stores the login token under during sign-out. */
    public const TOKEN_KEY = 'livenet-auth.id-token';

    public function __construct(
        private readonly string $fallbackUrl,
        private readonly string $endSessionUrl,
        private readonly string $returnUrl,
    ) {}

    public function toResponse($request): RedirectResponse
    {
        $token = app()->bound(self::TOKEN_KEY) ? app(self::TOKEN_KEY) : null;
        $target = $this->fallbackUrl;

        if (is_string($token) && $token !== '' && $this->endSessionUrl !== '') {
            $target = $this->endSessionUrl
                . (str_contains($this->endSessionUrl, '?') ? '&' : '?')
                . http_build_query(['id_token_hint' => $token, 'post_logout_redirect_uri' => $this->returnUrl]);
        }

        return redirect()->away($target)
            // The login-skip script leaves this behind for a minute. Clear it so the login
            // page we return to can send people to Authentik again right away.
            ->withCookie(Cookie::forget('ln_skip'))
            // An earlier version of this plugin kept the token in this cookie. Remove any leftover.
            ->withCookie(Cookie::forget('ln_idt'));
    }
}
