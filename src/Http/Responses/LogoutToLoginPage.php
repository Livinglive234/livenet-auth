<?php

namespace LiveNet\LiveNetAuth\Http\Responses;

use Filament\Auth\Http\Responses\Contracts\LogoutResponse;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;

/**
 * After the panel signs someone out, show the panel's normal login screen.
 *
 * "?stay" keeps the login-skip feature from sending them straight on to Authentik, so they
 * actually see the screen. Their Authentik login is not touched, so the LiveNet button signs
 * them straight back in without asking for credentials.
 */
class LogoutToLoginPage implements LogoutResponse
{
    public function toResponse($request): RedirectResponse
    {
        $login = Filament::hasLogin() ? Filament::getLoginUrl() : Filament::getUrl();

        return redirect()->to($login . (str_contains($login, '?') ? '&' : '?') . 'stay')
            ->withCookie(Cookie::forget('ln_skip'));
    }
}
