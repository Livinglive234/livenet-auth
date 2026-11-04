<?php

namespace LiveNet\LiveNetAuth\Providers;

use App\Enums\TablerIcon;
use App\Events\Server\SubUserAdded;
use App\Filament\Components\Actions\ConnectSftpAction;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use LiveNet\LiveNetAuth\Console\ReconcileUsers;
use LiveNet\LiveNetAuth\Http\Controllers\BackchannelLogout;
use LiveNet\LiveNetAuth\Http\Middleware\BlockPasskeys;
use LiveNet\LiveNetAuth\Http\Middleware\EndRevokedSessions;
use LiveNet\LiveNetAuth\Http\Responses\LogoutToAuthentik;
use LiveNet\LiveNetAuth\Http\Responses\LogoutToLoginPage;
use LiveNet\LiveNetAuth\Notifications\AuthentikPasswordReset;
use LiveNet\LiveNetAuth\Notifications\InvitedToServer;
use LiveNet\LiveNetAuth\Support\SftpPassword;
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

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileUsers::class]);
        }

        if (config('livenet-auth.reconcile_enabled')) {
            // Pelican's cron already runs the scheduler, so this runs once a day by itself.
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('livenet-auth:reconcile')->dailyAt('04:20')->withoutOverlapping();
            });
        }

        if (config('livenet-auth.hide_passkeys')) {
            $this->hidePasskeys();
        }

        if (config('livenet-auth.block_passkeys')) {
            $this->app['router']->pushMiddlewareToGroup('web', BlockPasskeys::class);
        }

        if (config('livenet-auth.disable_panel_2fa') || config('livenet-auth.show_mfa_notice')) {
            // The panel's "require 2FA" setting would send people to a tab that can no longer be
            // used to set 2FA up here, so make sure it is off.
            config(['panel.auth.2fa_required' => 0]);
        }

        if (config('livenet-auth.account_notice')) {
            $this->showAccountNotice();
        }

        if (config('livenet-auth.show_mfa_notice')) {
            $this->showTwoFactorNotice();
        }

        if (config('livenet-auth.reset_email_to_authentik')) {
            $this->replaceResetEmail();
        }

        $this->replaceLogoutResponse();

        if (config('livenet-auth.sftp_password')) {
            $this->replaceSftpButtons();
        }

        if (config('livenet-auth.back_to_site')) {
            $this->addBackToSiteButton();
        }

        if (config('livenet-auth.backchannel_logout')) {
            $this->trackLogin($provider);

            // No "web" middleware on purpose: Authentik's server calls this, with no session or CSRF token.
            Route::post('/livenet-auth/backchannel-logout', BackchannelLogout::class)
                ->middleware('throttle:30,1')
                ->name('livenet-auth.backchannel-logout');
        }
    }

    /**
     * Every SFTP button in the panel (the files page and each server's settings page) is a
     * ConnectSftpAction. Turn it into a popup styled like the delete confirmation, in the same
     * green: it asks for an SFTP password first if there is none, and otherwise offers
     * "Connect to SFTP", "Change SFTP password" and "Cancel".
     */
    private function replaceSftpButtons(): void
    {
        $min = max(8, (int) config('livenet-auth.sftp_password_min_length'));

        $fields = fn (): array => [
            TextInput::make('sftp_password')
                ->label('SFTP password')
                ->helperText('Only used for SFTP. It is separate from your LiveNet password.')
                ->password()
                ->revealable()
                ->required()
                ->minLength($min)
                ->maxLength(128)
                ->autocomplete('new-password')
                ->confirmed(),
            TextInput::make('sftp_password_confirmation')
                ->label('Repeat the SFTP password')
                ->password()
                ->revealable()
                ->required()
                ->autocomplete('new-password'),
        ];

        $save = function (array $data): void {
            SftpPassword::set(user(), (string) $data['sftp_password']);

            Notification::make()->title('SFTP password saved')->success()->send();
        };

        ConnectSftpAction::configureUsing(function (ConnectSftpAction $action) use ($fields, $save, $min): void {
            $url = fn (): ?string => Filament::getTenant()?->getSftpUrl($action->getUsername(), $action->getDirectory());

            $action
                ->url(null)
                ->color('success')
                ->requiresConfirmation()
                ->modalIconColor('success')
                ->modalHeading(fn (): string => SftpPassword::isSet(user()) ? 'Connect to SFTP' : 'Set an SFTP password')
                ->modalDescription(fn (): string => SftpPassword::isSet(user())
                    ? 'Your SFTP client will ask for your SFTP password.'
                    : "SFTP can't use your LiveNet password. Choose a separate SFTP password (at least {$min} characters) to connect.")
                ->modalSubmitActionLabel('Save password')
                ->modalSubmitAction(fn () => SftpPassword::isSet(user()) ? false : null)
                ->schema(fn (): array => SftpPassword::isSet(user()) ? [] : $fields())
                ->action(function (array $data) use ($save): void {
                    if (!SftpPassword::isSet(user()) && isset($data['sftp_password'])) {
                        $save($data);
                    }
                })
                ->extraModalFooterActions(fn (): array => SftpPassword::isSet(user()) ? [
                    Action::make('livenet_connect_sftp')
                        ->label('Connect to SFTP')
                        ->icon(TablerIcon::Plug)
                        ->color('success')
                        ->url($url),
                    Action::make('livenet_change_sftp_password')
                        ->label('Change SFTP password')
                        ->color('gray')
                        ->modalHeading('Change SFTP password')
                        ->modalWidth('md')
                        ->schema($fields())
                        ->action($save)
                        ->cancelParentActions(),
                ] : []);
        });

        // The settings page says the SFTP password is the panel password. It no longer is.
        try {
            $translator = $this->app['translator'];
            $translator->get('server/setting.server_info.sftp.password_body');
            $translator->addLines([
                'server/setting.server_info.sftp.password_body' => 'Use the Connect to SFTP button to set or change your SFTP password. It is separate from your LiveNet password.',
            ], 'en');
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** A link back to the main website, under the login form. */
    private function addBackToSiteButton(): void
    {
        FilamentView::registerRenderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, function (): HtmlString {
            $url = (string) config('livenet-auth.back_to_site_url');

            if (!preg_match('#^https?://#i', $url)) {
                return new HtmlString('');
            }

            return new HtmlString(
                '<div style="margin-top:1rem;text-align:center">'
                . '<a href="' . e($url) . '" style="display:inline-block;width:100%;padding:.5rem 1rem;border-radius:.5rem;'
                . 'border:1px solid rgba(128,128,128,.4);font-size:.875rem;font-weight:600;text-decoration:none;color:inherit">'
                . '&larr; ' . e((string) config('livenet-auth.back_to_site_label')) . '</a></div>'
            );
        });
    }

    /**
     * Remember which Authentik login a panel session came from, and when it began (by Authentik's
     * own clock), so a back-channel logout notice can end the right sessions.
     */
    private function trackLogin(string $provider): void
    {
        Event::listen(Login::class, function () use ($provider): void {
            try {
                $request = request();
                if (!$request->routeIs('auth.oauth.callback') || $request->route('driver') !== $provider) {
                    return;
                }

                $oauthUser = Socialite::driver($provider)->user();
                $idToken = $oauthUser->accessTokenResponseBody['id_token'] ?? null;
                $payload = is_string($idToken) ? explode('.', $idToken) : [];
                $claims = isset($payload[1])
                    ? json_decode((string) base64_decode(strtr($payload[1], '-_', '+/')), true)
                    : null;
                $claims = is_array($claims) ? $claims : [];

                $sub = (string) ($claims['sub'] ?? $oauthUser->getId());

                if ($sub !== '') {
                    session()->put(EndRevokedSessions::SUB_KEY, $sub);
                    session()->put(EndRevokedSessions::LOGIN_AT_KEY, (int) ($claims['iat'] ?? time()));
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        });
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
     * Choose what happens right after the panel signs someone out. Filament asks the container for
     * its "logout response", so we replace it. If a Filament update renames that class, we do
     * nothing instead of breaking sign-out.
     *
     * - logout_to_authentik on: end the Authentik login too, then return to the panel's login screen
     *   (/login?stay, so the login-skip does not bounce straight back to Authentik).
     * - otherwise, logout_show_login_page on: show the panel's login screen (login-skip paused).
     * - otherwise: leave Pelican's own behavior alone.
     */
    private function replaceLogoutResponse(): void
    {
        $toAuthentik = (bool) config('livenet-auth.logout_to_authentik');
        $toLoginPage = (bool) config('livenet-auth.logout_show_login_page');

        if (!$toAuthentik && !$toLoginPage) {
            return;
        }

        $contract = 'Filament\\Auth\\Http\\Responses\\Contracts\\LogoutResponse';

        if (!interface_exists($contract)) {
            report(new \RuntimeException("LiveNet Auth: {$contract} was not found, so sign-out was left alone."));

            return;
        }

        if (!$toAuthentik) {
            $this->app->bind($contract, fn () => new LogoutToLoginPage());

            return;
        }

        $url = (string) config('livenet-auth.logout_url');

        $this->app->bind($contract, fn () => new LogoutToAuthentik(
            $url,
            (string) config('livenet-auth.logout_end_session_url'),
            (string) (config('livenet-auth.logout_return_url') ?: url('/login?stay')),
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
                $linked = isset(self::asArray($event->notifiable->oauth)[(string) config('livenet-auth.provider')]);

                $event->notifiable->notify(new AuthentikPasswordReset(
                    (string) config('livenet-auth.recovery_url'),
                    $linked,
                    (string) config('livenet-auth.signup_url'),
                ));
            } catch (Throwable $exception) {
                report($exception);

                return null;
            }

            return false;
        });
    }

    /**
     * Hide every passkey button so nobody sets one up. This is only cosmetic: the passkey
     * routes still work. A small stylesheet handles the known buttons, and a short script
     * hides anything else on the page that mentions "passkey" (it also covers pages loaded
     * without a full reload, and translated labels).
     */
    private function hidePasskeys(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn (): HtmlString => new HtmlString(
                '<style>[x-on\\:click*="passkey" i],[wire\\:snapshot*="filament-passkeys"]{display:none!important}</style>'
                . '<script data-navigate-once>(function(){'
                . 'var re=/passkey/i,t;'
                . 'function hide(){document.querySelectorAll("button,a,[role=tab]").forEach(function(e){'
                . 'if(re.test(e.textContent||"")||re.test(e.getAttribute("x-on:click")||""))e.style.display="none";});}'
                . 'new MutationObserver(function(){cancelAnimationFrame(t);t=requestAnimationFrame(hide);})'
                . '.observe(document.documentElement,{childList:true,subtree:true});'
                . 'document.addEventListener("DOMContentLoaded",hide);'
                . '})();</script>'
            ),
        );
    }

    /** A notice on the profile page pointing to the LiveNet account page. */
    private function showAccountNotice(): void
    {
        FilamentView::registerRenderHook(PanelsRenderHook::PAGE_START, function (): HtmlString {
            if (!Str::is('filament.*.auth.profile', (string) (request()->route()?->getName() ?? ''))) {
                return new HtmlString('');
            }

            $url = (string) config('livenet-auth.account_page_url');

            // Filament's own button, so it matches the panel's theme (colours, size, dark mode).
            $button = Blade::render(
                '<x-filament::button tag="a" :href="$url">Edit on livenet.host</x-filament::button>',
                ['url' => $url],
            );

            return new HtmlString(
                '<div style="margin:0 0 1rem;display:flex;flex-wrap:wrap;gap:0.75rem;align-items:center;justify-content:space-between">'
                . '<span>Your username, email and password are managed on your LiveNet account.</span>' . $button . '</div>'
            );
        });
    }

    /**
     * Send the profile page's 2FA tab to the LiveNet account's 2FA page.
     *
     * Normally the tab stays exactly as Pelican draws it, and a click on it is taken over and
     * redirected. If the tab was removed (LIVENET_AUTH_DISABLE_PANEL_2FA), a plain button is
     * shown instead. The old tab address (?tab=2fa) is redirected either way.
     */
    private function showTwoFactorNotice(): void
    {
        FilamentView::registerRenderHook(PanelsRenderHook::PAGE_START, function (): HtmlString {
            $route = (string) (request()->route()?->getName() ?? 'none');
            $onProfile = Str::is('filament.*.auth.profile', $route);

            // A harmless marker, so "view source" shows what this hook saw.
            $marker = '<!-- livenet-auth 2fa-notice: route=' . e($route) . ' profile=' . ($onProfile ? 'yes' : 'no') . ' -->';

            if (!$onProfile) {
                return new HtmlString($marker);
            }

            $url = (string) config('livenet-auth.mfa_page_url');
            $target = json_encode($url, JSON_UNESCAPED_SLASHES);

            // Anyone who opens the old 2FA tab's address goes to the new page.
            $redirect = '<script>if(/[?&]tab=2fa/.test(location.search))location.replace(' . $target . ');</script>';

            if (config('livenet-auth.disable_panel_2fa')) {
                // The tab is gone, so show a plain button in its place.
                $button = 'display:inline-block;padding:0.4rem 0.9rem;border-radius:0.5rem;'
                    . 'border:1px solid currentColor;text-decoration:none;font-weight:600;';

                return new HtmlString(
                    $marker . $redirect
                    . '<div style="margin:0 0 1rem"><a href="' . e($url) . '" style="' . $button . '">Two-factor authentication</a></div>'
                );
            }

            // The tab is kept. Catch clicks on it before Pelican's tab code does, and go to the
            // new page instead. The tab is found by its click handler or, failing that, by its label.
            $takeover = '<script>(function(u){'
                . 'if(window.__lnTab2fa)return;window.__lnTab2fa=true;'
                . 'function find(el){var t=el.closest&&el.closest("[role=tab],button");if(!t)return null;'
                . 'if((t.getAttribute("x-on:click")||"").indexOf("2fa::data::tab")>-1)return t;'
                . 'if(t.getAttribute("role")==="tab"&&/^\\s*(2fa|two[- ]?factor)/i.test(t.textContent||""))return t;return null;}'
                . 'document.addEventListener("click",function(e){if(!find(e.target))return;'
                . 'e.preventDefault();e.stopPropagation();e.stopImmediatePropagation();location.href=u;},true);'
                . '})(' . $target . ');</script>';

            return new HtmlString($marker . $redirect . $takeover);
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
