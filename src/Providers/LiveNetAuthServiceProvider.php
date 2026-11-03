<?php

namespace LiveNet\LiveNetAuth\Providers;

use App\Events\Server\SubUserAdded;
use App\Models\User;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use LiveNet\LiveNetAuth\Http\Responses\LogoutToAuthentik;
use LiveNet\LiveNetAuth\Notifications\AuthentikPasswordReset;
use LiveNet\LiveNetAuth\Notifications\InvitedToServer;
use Throwable;

class LiveNetAuthServiceProvider extends ServiceProvider
{
    /** Session key holding Authentik's login token (the ID token), so a sign-out can tell Authentik which login to end. */
    private const SESSION_KEY = 'livenet-auth.id-token';

    public function register(): void
    {
        $this->mergeConfigFrom(plugin_path('livenet-auth', 'config/livenet-auth.php'), 'livenet-auth');
    }

    public function boot(): void
    {
        if (!config('livenet-auth.enabled')) {
            return;
        }

        $provider = (string) config('livenet-auth.provider');

        if (config('livenet-auth.protect_link')) {
            $this->protectLink($provider);
        }

        if (config('livenet-auth.lock_synced_users')) {
            $this->lockSyncedUsers($provider);
        }

        if (config('livenet-auth.sync_username')) {
            $this->syncUsername($provider);
        }

        if (config('livenet-auth.invite_emails')) {
            $this->sendInvitations($provider);
        }

        if (config('livenet-auth.skip_login_page')) {
            $this->skipLoginPage($provider);
        }

        if (config('livenet-auth.redirect_password_reset')) {
            $this->redirectPasswordReset();
        }

        if (config('livenet-auth.reset_email_to_authentik')) {
            $this->replaceResetEmail();
        }

        if (config('livenet-auth.logout_to_authentik')) {
            $this->logoutToAuthentik();
        }
    }

    /**
     * The profile page's OAuth tab removes a link by saving the user without it.
     * Put the Authentik link back before that save happens. Console commands are
     * left alone, so an admin can still change a link with tinker.
     */
    private function protectLink(string $provider): void
    {
        User::updating(function (User $user) use ($provider): void {
            if (app()->runningInConsole()) {
                return;
            }

            $before = self::asArray($user->getOriginal('oauth'));
            $after = self::asArray($user->oauth);

            if (isset($before[$provider]) && !isset($after[$provider])) {
                $after[$provider] = $before[$provider];
                $user->oauth = $after;
            }
        });

        // Hide the Authentik link/unlink button. This is only cosmetic: the check
        // above is what actually keeps the link in place.
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn (): HtmlString => new HtmlString('<style>[wire\\:click*="oauth_' . e($provider) . '"]{display:none!important}</style>'),
        );
    }

    /** Anyone linked to Authentik (except root admins) becomes "managed externally". */
    private function lockSyncedUsers(string $provider): void
    {
        User::saved(function (User $user) use ($provider): void {
            if ($user->is_managed_externally) {
                return;
            }

            if (!isset(self::asArray($user->oauth)[$provider])) {
                return;
            }

            if ($user->isRootAdmin()) {
                return;
            }

            $user->forceFill(['is_managed_externally' => true])->saveQuietly();
        });
    }

    /**
     * Copy the Authentik username onto the panel account when someone signs in with
     * Authentik. Pelican only uses it when it creates a brand-new account, so a person
     * who was first created by a subuser invite would keep the placeholder name.
     *
     * Socialite remembers the person it fetched earlier in this same request, so asking
     * for the user again here does not call Authentik a second time.
     */
    private function syncUsername(string $provider): void
    {
        Event::listen(Login::class, function (Login $event) use ($provider): void {
            try {
                $request = request();
                if (!$request->routeIs('auth.oauth.callback') || $request->route('driver') !== $provider) {
                    return;
                }

                $user = $event->user;
                if (!$user instanceof User || $user->isRootAdmin()) {
                    return;
                }

                $username = trim((string) Socialite::driver($provider)->user()->getNickname());
                if ($username === '' || mb_strlen($username) > 255 || $username === $user->username) {
                    return;
                }

                $taken = User::query()->where('username', $username)->where('id', '!=', $user->id)->exists();
                if ($taken) {
                    report(new \RuntimeException("LiveNet Auth: the username \"{$username}\" is already used on the panel, so \"{$user->username}\" was kept."));

                    return;
                }

                $user->forceFill(['username' => $username])->saveQuietly();
            } catch (Throwable $exception) {
                // Never stop someone from signing in over a name.
                report($exception);
            }
        });
    }

    /** Tell people who are added as a subuser how to get into the panel. */
    private function sendInvitations(string $provider): void
    {
        Event::listen(SubUserAdded::class, function (SubUserAdded $event) use ($provider): void {
            try {
                $event->subuser->loadMissing(['user', 'server']);

                $user = $event->subuser->user;
                $server = $event->subuser->server;
                if (!$user || !$server) {
                    return;
                }

                $linked = isset(self::asArray($user->oauth)[$provider]);

                $user->notify(new InvitedToServer($server, user()?->username, $linked));
            } catch (Throwable $exception) {
                // An email problem must never stop the invitation itself.
                report($exception);
            }
        });
    }

    /**
     * Send people straight to Authentik. A short-lived cookie stops a loop if the
     * sign-in fails and the panel sends them back to the login page.
     */
    private function skipLoginPage(string $provider): void
    {
        FilamentView::registerRenderHook(PanelsRenderHook::BODY_START, function () use ($provider): HtmlString {
            $route = (string) (request()->route()?->getName() ?? 'none');
            $isLogin = Str::is('filament.*.auth.login', $route);

            // A harmless marker, so "view source" shows whether this hook ran and what it saw.
            $marker = '<!-- livenet-auth skip-login: route=' . e($route) . ' login=' . ($isLogin ? 'yes' : 'no') . ' -->';

            if (!$isLogin) {
                return new HtmlString($marker);
            }

            $target = json_encode(route('auth.oauth.redirect', ['driver' => $provider], false), JSON_UNESCAPED_SLASHES);

            return new HtmlString(
                $marker
                . '<script>(function(){'
                . 'if(location.search.indexOf("stay")>-1||/(?:^|; )ln_skip=1/.test(document.cookie))return;'
                . 'document.cookie="ln_skip=1; Max-Age=60; Path=/; SameSite=Lax";'
                . 'location.replace(' . $target . ');'
                . '})();</script>'
            );
        });
    }

    /**
     * The panel's own "forgot password" page only sets a panel password, which can no longer
     * be used to sign in. Send people to Authentik's recovery flow instead.
     */
    private function redirectPasswordReset(): void
    {
        FilamentView::registerRenderHook(PanelsRenderHook::BODY_START, function (): HtmlString {
            $route = (string) (request()->route()?->getName() ?? 'none');
            $matches = Str::is('filament.*.auth.password-reset.request', $route);

            $marker = '<!-- livenet-auth recovery: route=' . e($route) . ' match=' . ($matches ? 'yes' : 'no') . ' -->';

            if (!$matches) {
                return new HtmlString($marker);
            }

            $url = (string) config('livenet-auth.recovery_url');

            return new HtmlString(
                $marker
                . '<script>location.replace(' . json_encode($url, JSON_UNESCAPED_SLASHES) . ');</script>'
                . '<noscript><p style="text-align:center;margin:2rem"><a href="' . e($url) . '">Reset your password with your LiveNet account</a></p></noscript>'
            );
        });
    }

    /**
     * After the panel signs someone out, send them to Authentik's logout so the Authentik
     * login ends too. Filament asks the container for its "logout response", so we replace
     * it. If a Filament update renames that class, we do nothing instead of breaking sign-out.
     */
    private function logoutToAuthentik(): void
    {
        $contract = 'Filament\\Auth\\Http\\Responses\\Contracts\\LogoutResponse';

        if (!interface_exists($contract)) {
            report(new \RuntimeException("LiveNet Auth: {$contract} was not found, so sign-out was left alone."));

            return;
        }

        $url = (string) config('livenet-auth.logout_url');

        $this->app->bind($contract, fn () => new LogoutToAuthentik(
            $url,
            (string) config('livenet-auth.logout_end_session_url'),
            (string) (config('livenet-auth.logout_return_url') ?: url('/login')),
        ));

        $this->rememberLoginToken((string) config('livenet-auth.provider'));
    }

    /**
     * Keep Authentik's login token in the panel's session when someone signs in, and copy
     * it into the container just before sign-out clears the session, so the logout response
     * can use it. (A cookie would be far too large: it made the sign-in fail with a 502.)
     *
     * Socialite remembers the user it fetched earlier in this request, so reading the token
     * here does not call Authentik again.
     */
    private function rememberLoginToken(string $provider): void
    {
        Event::listen(Login::class, function (Login $event) use ($provider): void {
            try {
                $request = request();
                if (!$request->routeIs('auth.oauth.callback') || $request->route('driver') !== $provider) {
                    return;
                }

                $oauthUser = Socialite::driver($provider)->user();
                $idToken = $oauthUser->accessTokenResponseBody['id_token'] ?? null;

                if (is_string($idToken) && $idToken !== '') {
                    session()->put(self::SESSION_KEY, $idToken);
                }
            } catch (Throwable $exception) {
                // Without the token, sign-out still ends the Authentik login, just without the return trip.
                report($exception);
            }
        });

        // This fires while signing out, before the session is cleared.
        Event::listen(Logout::class, function (): void {
            try {
                $token = session()->get(self::SESSION_KEY);

                if (is_string($token) && $token !== '') {
                    app()->instance(LogoutToAuthentik::TOKEN_KEY, $token);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * Laravel announces every notification just before it is sent. When it is Pelican's
     * "reset your password" email, cancel it and send ours instead, which links to
     * Authentik's recovery flow. If ours cannot be sent, Pelican's email goes out as usual.
     */
    private function replaceResetEmail(): void
    {
        Event::listen(NotificationSending::class, function (NotificationSending $event): ?bool {
            if (!$event->notification instanceof \Filament\Auth\Notifications\ResetPassword) {
                return null;
            }

            if ($event->channel !== 'mail' || !$event->notifiable instanceof User) {
                return null;
            }

            try {
                $event->notifiable->notify(new AuthentikPasswordReset((string) config('livenet-auth.recovery_url')));
            } catch (Throwable $exception) {
                report($exception);

                return null;
            }

            return false;
        });
    }

    /** @return array<string, mixed> */
    private static function asArray(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }
}
