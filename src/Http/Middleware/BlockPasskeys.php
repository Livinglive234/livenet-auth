<?php

namespace LiveNet\LiveNetAuth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passkey sign-in would skip Authentik entirely, so block the passkey routes
 * (named "passkey.*" by the Laravel passkeys package) with a 404.
 */
class BlockPasskeys
{
    public function handle(Request $request, Closure $next): Response
    {
        $name = $request->route()?->getName();

        if (is_string($name) && str_starts_with($name, 'passkey.')) {
            abort(404);
        }

        return $next($request);
    }
}
