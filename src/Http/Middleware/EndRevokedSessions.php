<?php

namespace LiveNet\LiveNetAuth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Ends a panel session when Authentik reported (back-channel logout) that the person's
 * login ended after this session began. Only sessions that came from an Authentik sign-in
 * are touched, so a break-glass password login is never ended by this.
 */
class EndRevokedSessions
{
    public const SUB_KEY = 'livenet-auth.sub';

    public const LOGIN_AT_KEY = 'livenet-auth.login-at';

    public static function cacheKey(string $sub): string
    {
        return 'livenet-auth.logout.' . hash('sha256', $sub);
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $session = $request->session();
            $sub = $session->get(self::SUB_KEY);

            if (is_string($sub) && $sub !== '' && Auth::check()) {
                $loggedOutAt = (int) Cache::get(self::cacheKey($sub), 0);

                if ($loggedOutAt > 0 && (int) $session->get(self::LOGIN_AT_KEY, 0) < $loggedOutAt) {
                    Auth::logout();
                    $session->invalidate();
                    $session->regenerateToken();

                    // A page-update request cannot follow a redirect, so make the browser reload instead.
                    if ($request->headers->has('X-Livewire')) {
                        return response('', 419);
                    }

                    return redirect()->to(url('/login?stay'));
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return $next($request);
    }
}
