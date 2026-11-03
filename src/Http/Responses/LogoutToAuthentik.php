<?php

namespace LiveNet\LiveNetAuth\Http\Responses;

use Filament\Auth\Http\Responses\Contracts\LogoutResponse;
use Illuminate\Http\RedirectResponse;

/**
 * What happens right after the panel signs someone out: go to Authentik's logout,
 * so signing out of the panel also ends the Authentik login.
 */
class LogoutToAuthentik implements LogoutResponse
{
    public function __construct(private readonly string $url) {}

    public function toResponse($request): RedirectResponse
    {
        return redirect()->away($this->url);
    }
}
