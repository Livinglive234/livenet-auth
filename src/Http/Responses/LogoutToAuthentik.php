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
 */
class LogoutToAuthentik implements LogoutResponse
{
    public function __construct(
        private readonly string $fallbackUrl,
        private readonly string $endSessionUrl,
        private readonly string $returnUrl,
        private readonly string $tokenCookie,
    ) {}

    public function toResponse($request): RedirectResponse
    {
        $token = $request->cookie($this->tokenCookie);
        $target = $this->fallbackUrl;

        if (is_string($token) && $token !== '' && $this->endSessionUrl !== '') {
            $target = $this->endSessionUrl
                . (str_contains($this->endSessionUrl, '?') ? '&' : '?')
                . http_build_query(['id_token_hint' => $token, 'post_logout_redirect_uri' => $this->returnUrl]);
        }

        return redirect()->away($target)
            ->withCookie(Cookie::forget($this->tokenCookie))
            // The login-skip script leaves this behind for a minute. Clear it so the login
            // page we return to can send people to Authentik again right away.
            ->withCookie(Cookie::forget('ln_skip'));
    }
}
