<?php

namespace LiveNet\LiveNetAuth\Providers;

use App\Events\Server\SubUserAdded;
use App\Models\User;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;
use LiveNet\LiveNetAuth\Notifications\InvitedToServer;
use Throwable;

class LiveNetAuthServiceProvider extends ServiceProvider
{
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

        if (config('livenet-auth.invite_emails')) {
            $this->sendInvitations($provider);
        }

        if (config('livenet-auth.skip_login_page')) {
            $this->skipLoginPage($provider);
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
            if (!request()->routeIs('filament.*.auth.login')) {
                return new HtmlString('');
            }

            $target = json_encode(route('auth.oauth.redirect', ['driver' => $provider], false), JSON_UNESCAPED_SLASHES);

            return new HtmlString(
                '<script>(function(){'
                . 'if(location.search.indexOf("stay")>-1||/(?:^|; )ln_skip=1/.test(document.cookie))return;'
                . 'document.cookie="ln_skip=1; Max-Age=60; Path=/; SameSite=Lax";'
                . 'location.replace(' . $target . ');'
                . '})();</script>'
            );
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
