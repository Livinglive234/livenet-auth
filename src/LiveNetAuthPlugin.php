<?php

namespace LiveNet\LiveNetAuth;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Artisan;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use LiveNet\LiveNetAuth\Filament\Pages\OrphanedAccounts;
use LiveNet\LiveNetAuth\Http\Middleware\EndRevokedSessions;

/**
 * The work happens in Providers/LiveNetAuthServiceProvider.php, which Pelican loads
 * automatically. This class identifies the plugin and provides its settings page
 * (Admin -> Plugins -> LiveNet Auth -> Settings), which writes to the panel's .env file.
 */
class LiveNetAuthPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    /**
     * Settings form key => [.env name, where the current value is read from, type].
     * Pelican's own settings are included at the bottom so they can be changed here too.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const OPTIONS = [
        'enabled' => ['LIVENET_AUTH_ENABLED', 'livenet-auth.enabled', 'bool'],
        'provider' => ['LIVENET_AUTH_PROVIDER', 'livenet-auth.provider', 'string'],
        'protect_link' => ['LIVENET_AUTH_PROTECT_LINK', 'livenet-auth.protect_link', 'bool'],
        'lock_synced_users' => ['LIVENET_AUTH_LOCK_SYNCED_USERS', 'livenet-auth.lock_synced_users', 'bool'],
        'sync_username' => ['LIVENET_AUTH_SYNC_USERNAME', 'livenet-auth.sync_username', 'bool'],
        'invite_emails' => ['LIVENET_AUTH_INVITE_EMAILS', 'livenet-auth.invite_emails', 'bool'],
        'signup_url' => ['LIVENET_AUTH_SIGNUP_URL', 'livenet-auth.signup_url', 'string'],
        'redirect_password_reset' => ['LIVENET_AUTH_REDIRECT_PASSWORD_RESET', 'livenet-auth.redirect_password_reset', 'bool'],
        'recovery_url' => ['LIVENET_AUTH_RECOVERY_URL', 'livenet-auth.recovery_url', 'string'],
        'hide_passkeys' => ['LIVENET_AUTH_HIDE_PASSKEYS', 'livenet-auth.hide_passkeys', 'bool'],
        'block_passkeys' => ['LIVENET_AUTH_BLOCK_PASSKEYS', 'livenet-auth.block_passkeys', 'bool'],
        'disable_panel_2fa' => ['LIVENET_AUTH_DISABLE_PANEL_2FA', 'livenet-auth.disable_panel_2fa', 'bool'],
        'show_mfa_notice' => ['LIVENET_AUTH_SHOW_MFA_NOTICE', 'livenet-auth.show_mfa_notice', 'bool'],
        'mfa_page_url' => ['LIVENET_AUTH_MFA_PAGE_URL', 'livenet-auth.mfa_page_url', 'string'],
        'reset_email_to_authentik' => ['LIVENET_AUTH_RESET_EMAIL_TO_AUTHENTIK', 'livenet-auth.reset_email_to_authentik', 'bool'],
        'logout_show_login_page' => ['LIVENET_AUTH_LOGOUT_SHOW_LOGIN_PAGE', 'livenet-auth.logout_show_login_page', 'bool'],
        'logout_to_authentik' => ['LIVENET_AUTH_LOGOUT_TO_AUTHENTIK', 'livenet-auth.logout_to_authentik', 'bool'],
        'backchannel_logout' => ['LIVENET_AUTH_BACKCHANNEL_LOGOUT', 'livenet-auth.backchannel_logout', 'bool'],
        'sftp_password' => ['LIVENET_AUTH_SFTP_PASSWORD', 'livenet-auth.sftp_password', 'bool'],
        'show_oauth_notice' => ['LIVENET_AUTH_SHOW_OAUTH_NOTICE', 'livenet-auth.show_oauth_notice', 'bool'],
        'oauth_page_url' => ['LIVENET_AUTH_OAUTH_PAGE_URL', 'livenet-auth.oauth_page_url', 'string'],
        'account_notice' => ['LIVENET_AUTH_ACCOUNT_NOTICE', 'livenet-auth.account_notice', 'bool'],
        'account_page_url' => ['LIVENET_AUTH_ACCOUNT_PAGE_URL', 'livenet-auth.account_page_url', 'string'],
        'user_menu_link' => ['LIVENET_AUTH_USER_MENU_LINK', 'livenet-auth.user_menu_link', 'bool'],
        'user_menu_label' => ['LIVENET_AUTH_USER_MENU_LABEL', 'livenet-auth.user_menu_label', 'string'],
        'back_to_site' => ['LIVENET_AUTH_BACK_TO_SITE', 'livenet-auth.back_to_site', 'bool'],
        'back_to_site_url' => ['LIVENET_AUTH_BACK_TO_SITE_URL', 'livenet-auth.back_to_site_url', 'string'],
        'logout_url' => ['LIVENET_AUTH_LOGOUT_URL', 'livenet-auth.logout_url', 'string'],
        'logout_end_session_url' => ['LIVENET_AUTH_LOGOUT_END_SESSION_URL', 'livenet-auth.logout_end_session_url', 'string'],
        'logout_return_url' => ['LIVENET_AUTH_LOGOUT_RETURN_URL', 'livenet-auth.logout_return_url', 'string'],
        'skip_login_page' => ['LIVENET_AUTH_SKIP_LOGIN', 'livenet-auth.skip_login_page', 'bool'],

        'reconcile_enabled' => ['LIVENET_AUTH_RECONCILE_ENABLED', 'livenet-auth.reconcile_enabled', 'bool'],
        'reconcile_delete' => ['LIVENET_AUTH_RECONCILE_DELETE', 'livenet-auth.reconcile_delete', 'bool'],
        'reconcile_max_deletes' => ['LIVENET_AUTH_RECONCILE_MAX_DELETES', 'livenet-auth.reconcile_max_deletes', 'string'],
        'authentik_url' => ['LIVENET_AUTH_AUTHENTIK_URL', 'livenet-auth.authentik_url', 'string'],
        'authentik_api_token' => ['LIVENET_AUTH_AUTHENTIK_API_TOKEN', 'livenet-auth.authentik_api_token', 'secret'],

        'disable_password_login' => ['AUTH_DISABLE_PASSWORD_LOGIN', 'auth.disable_password_login', 'bool'],
        'send_account_created' => ['PANEL_SEND_ACCOUNT_CREATED_NOTIFICATION', 'panel.email.send_account_created_notification', 'bool'],
        'send_added_to_server' => ['PANEL_SEND_ADDED_TO_SERVER_NOTIFICATION', 'panel.email.send_added_to_server_notification', 'bool'],
    ];

    public function getId(): string
    {
        return 'livenet-auth';
    }

    public function register(Panel $panel): void
    {
        if (config('livenet-auth.enabled', true) && config('livenet-auth.backchannel_logout', true)) {
            try {
                $panel->authMiddleware([EndRevokedSessions::class], isPersistent: true);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        // A "Return to LiveNet" link in the menu that opens from the profile picture, on every panel.
        if (config('livenet-auth.enabled', true) && config('livenet-auth.user_menu_link', true)) {
            try {
                $url = (string) config('livenet-auth.back_to_site_url');

                if (preg_match('#^https?://#i', $url)) {
                    $panel->userMenuItems([
                        'livenet-return' => Action::make('livenet-return')
                            ->label((string) config('livenet-auth.user_menu_label', 'Return to LiveNet'))
                            ->icon('tabler-arrow-back-up')
                            ->url($url),
                    ]);
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        // The "Orphaned accounts" admin page. Only the admin panel gets it.
        if ($panel->getId() === 'admin' && config('livenet-auth.enabled', true)) {
            try {
                $panel->pages([OrphanedAccounts::class]);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        // Optional: drop the panel's own two-factor options, which also removes the 2FA tab on the
        // profile page. Off by default, because the tab is kept and redirected instead. A failure
        // here must never break the panel.
        if (config('livenet-auth.disable_panel_2fa', env('LIVENET_AUTH_DISABLE_PANEL_2FA', false))) {
            try {
                $panel->multiFactorAuthentication([]);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }

    public function boot(Panel $panel): void {}

    /** @return array<string, mixed> */
    public function getSettingsFormData(): array
    {
        $data = [];

        foreach (self::OPTIONS as $key => [, $configKey, $type]) {
            $value = config($configKey);
            $data[$key] = $type === 'bool' ? (bool) $value : ($type === 'secret' ? '' : (string) ($value ?? ''));
        }

        return $data;
    }

    /** @return \Filament\Schemas\Components\Component[] */
    public function getSettingsForm(): array
    {
        return [
            Section::make('Plugin')
                ->description('Changes apply as soon as you save. Nothing here needs a cache clear.')
                ->schema([
                    Toggle::make('enabled')
                        ->label('Plugin enabled')
                        ->helperText('Master switch. Off turns every feature below off.'),
                    TextInput::make('provider')
                        ->label('Provider id')
                        ->helperText('The id of the Authentik provider in the panel\'s OAuth settings.')
                        ->required()
                        ->maxLength(64)
                        ->regex('/^[A-Za-z0-9_-]+$/'),
                ]),

            Section::make('Accounts')
                ->schema([
                    Toggle::make('protect_link')
                        ->label('Keep the Authentik link')
                        ->helperText('Stops people removing their Authentik link, and hides the button for it.'),
                    Toggle::make('lock_synced_users')
                        ->label('Lock synced accounts')
                        ->helperText('Marks accounts linked to Authentik as "managed externally". Root admins are skipped.'),
                    Toggle::make('sync_username')
                        ->label('Keep usernames in step')
                        ->helperText('Copies the Authentik username onto the panel account at sign-in. Root admins are skipped. (Not tested yet.)'),
                ]),

            Section::make('Other ways to sign in')
                ->description('Authentik handles sign-in and 2FA now. These remove the panel\'s own options so nothing skips Authentik.')
                ->schema([
                    Toggle::make('hide_passkeys')
                        ->label('Hide passkey buttons')
                        ->helperText('So nobody sets one up. Only hides the buttons: existing passkeys and the sign-in route still work.'),
                    Toggle::make('block_passkeys')
                        ->label('Also block passkey sign-in completely')
                        ->helperText('Makes the passkey routes return "not found", so passkeys cannot be used at all. A passkey would skip Authentik.'),
                    Toggle::make('disable_panel_2fa')
                        ->label('Remove the 2FA tab entirely')
                        ->helperText('Off by default: the tab stays and its click goes to the LiveNet account\'s 2FA page. Turn this on to remove the tab (and the panel\'s own 2FA options), and show a plain button instead. Takes effect after the page reloads.'),
                ]),

            Section::make('Profile page')
                ->description('The panel locks usernames, emails and passwords for LiveNet accounts.')
                ->schema([
                    Toggle::make('account_notice')
                        ->label('Show a link to the LiveNet account page')
                        ->helperText('Adds a notice at the top of the profile page with a button to the page below.'),
                    TextInput::make('account_page_url')
                        ->label('LiveNet account page')
                        ->url()
                        ->required()
                        ->maxLength(255),
                    Toggle::make('show_oauth_notice')
                        ->label('Send the OAuth tab to the LiveNet account')
                        ->helperText('Clicking the profile page\'s OAuth tab, or opening its old address, goes to the page below, where linked sign-ins are managed.'),
                    TextInput::make('oauth_page_url')
                        ->label('LiveNet linked accounts page')
                        ->url()
                        ->required()
                        ->maxLength(255),
                ]),

            Section::make('Two-factor authentication')
                ->description('Shown once the panel\'s own 2FA is removed.')
                ->schema([
                    Toggle::make('show_mfa_notice')
                        ->label('Send the 2FA tab to the LiveNet account')
                        ->helperText('Clicking the profile page\'s 2FA tab goes to the page below, and so does the old 2FA tab address. Also forces the panel\'s "require 2FA" setting off.'),
                    TextInput::make('mfa_page_url')
                        ->label('LiveNet account 2FA page')
                        ->helperText('The website page where people set up an authenticator app and backup codes.')
                        ->url()
                        ->required()
                        ->maxLength(255),
                ]),

            Section::make('Clean up deleted accounts')
                ->description('Once a day, removes panel accounts whose LiveNet (Authentik) account no longer exists, so a stranger who later signs up with that email cannot be linked to the old account. Needs a read-only Authentik token. Never touches admins or accounts that own servers.')
                ->schema([
                    Toggle::make('reconcile_enabled')
                        ->label('Run the daily check')
                        ->helperText('Takes effect on the next scheduled run. To see what it would do right now: php artisan livenet-auth:reconcile --dry-run'),
                    Toggle::make('reconcile_delete')
                        ->label('Delete the accounts it finds')
                        ->helperText('Off = report only (to the panel log and the command output). Leave this off until you have seen a report that looks right.'),
                    TextInput::make('reconcile_max_deletes')
                        ->label('Most accounts it may delete in one run')
                        ->helperText('If it finds more than this, it deletes nothing and logs a warning.')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(50)
                        ->required(),
                    TextInput::make('authentik_url')
                        ->label('Authentik address')
                        ->url()
                        ->required()
                        ->maxLength(255),
                    TextInput::make('authentik_api_token')
                        ->label('Authentik read-only API token')
                        ->helperText('A token for a service account that can only view users. Saved in .env. Leave blank to keep the saved one.')
                        ->password()
                        ->revealable()
                        ->autocomplete(false)
                        ->maxLength(255),
                ]),

            Section::make('Invitations')
                ->schema([
                    Toggle::make('invite_emails')
                        ->label('Send invite emails to new subusers')
                        ->helperText('Explains how to create a LiveNet account. Turn off Pelican\'s own "added to server" email below, so they do not get two.'),
                    TextInput::make('signup_url')
                        ->label('Account creation address')
                        ->helperText('Where "Create your LiveNet account" in that email goes.')
                        ->url()
                        ->required()
                        ->maxLength(255),
                ]),

            Section::make('Signing in and out')
                ->schema([
                    Toggle::make('skip_login_page')
                        ->label('Skip the login page')
                        ->helperText('Go straight to Authentik. Add ?stay to /login to see the normal page.'),
                    Toggle::make('redirect_password_reset')
                        ->label('Send "forgot password" to Authentik')
                        ->helperText('Panel passwords can no longer be used to sign in.'),
                    TextInput::make('recovery_url')
                        ->label('Authentik recovery flow address')
                        ->url()
                        ->required()
                        ->maxLength(255),
                    Toggle::make('reset_email_to_authentik')
                        ->label('Replace the panel\'s password reset email')
                        ->helperText('Includes the admin Users page\'s "Reset password" button. The email links to Authentik\'s recovery flow instead.'),
                    Toggle::make('logout_show_login_page')
                        ->label('Without ending the Authentik login, still show the panel\'s login screen')
                        ->helperText('Only used when the next toggle is off. Your Authentik login stays active, so the LiveNet button signs you straight back in.'),
                    Toggle::make('logout_to_authentik')
                        ->label('Signing out also ends the Authentik login')
                        ->helperText('Then returns to the panel\'s login screen. Needs the return address below added as a "Logout" Redirect URI on the panel\'s provider in Authentik.'),
                    Toggle::make('sftp_password')
                        ->label('SFTP buttons open a popup, with a separate SFTP password')
                        ->helperText('People choose an SFTP password the first time they click an SFTP button. Takes effect after the page reloads.'),
                    Toggle::make('back_to_site')
                        ->label('Show a "Back to livenet.host" button on the login page')
                        ->helperText('Appears under the login form. Takes effect after the page reloads.'),
                    TextInput::make('back_to_site_url')
                        ->label('Where that button goes')
                        ->url()
                        ->maxLength(255),
                    Toggle::make('user_menu_link')
                        ->label('Add a "Return to LiveNet" link to the profile picture menu')
                        ->helperText('Appears in the menu that opens when you click your profile picture, and goes to the same address as the button above.'),
                    TextInput::make('user_menu_label')
                        ->label('Text of that link')
                        ->maxLength(60),
                    Toggle::make('backchannel_logout')
                        ->label('Sign out here when the Authentik login ends elsewhere')
                        ->helperText('Authentik tells the panel (back-channel logout). Needs the panel provider\'s Logout URI set to https://panel.livenet.host/livenet-auth/backchannel-logout with the Back-channel method. Takes effect for sessions started after this is on.'),
                    TextInput::make('logout_url')
                        ->label('General logout address')
                        ->helperText('Authentik\'s own logout. Used when the login token is not available.')
                        ->url()
                        ->required()
                        ->maxLength(255),
                    TextInput::make('logout_end_session_url')
                        ->label('Panel app end-session address')
                        ->helperText('Leave empty to use the general logout address only. The panel\'s login address must be a "Logout" Redirect URI on the provider.')
                        ->url()
                        ->maxLength(255),
                    TextInput::make('logout_return_url')
                        ->label('Return address after sign-out')
                        ->helperText('Leave empty to use the panel\'s /login?stay. Whatever it is must be a "Logout" Redirect URI in Authentik, exactly.')
                        ->url()
                        ->maxLength(255),
                ]),

            Section::make('Pelican\'s own settings')
                ->description('These belong to the panel itself, not this plugin. They are here so you do not have to edit .env.')
                ->schema([
                    Toggle::make('disable_password_login')
                        ->label('Turn off password login')
                        ->helperText('Warning: blocks password sign-in for everyone, admins included. Only turn this on once signing in with Authentik works for your own account. If it goes wrong, set AUTH_DISABLE_PASSWORD_LOGIN=false in .env over SSH.'),
                    Toggle::make('send_account_created')
                        ->label('Email new accounts a "set a password" link')
                        ->helperText('Leave off: panel passwords cannot be used to sign in.'),
                    Toggle::make('send_added_to_server')
                        ->label('Send Pelican\'s own "added to server" email')
                        ->helperText('Leave off if the invite emails above are on.'),
                ]),
        ];
    }

    /** @param array<mixed, mixed> $data */
    public function saveSettings(array $data): void
    {
        $values = [];

        foreach (self::OPTIONS as $key => [$envName, , $type]) {
            $value = $data[$key] ?? ($type === 'bool' ? false : '');

            // A blank secret means "keep the one that is saved".
            if ($type === 'secret' && trim((string) $value) === '') {
                continue;
            }

            $values[$envName] = $type === 'bool' ? ($value ? 'true' : 'false') : trim((string) $value);
        }

        $this->writeToEnvironment($values);

        // The queue worker (which sends the emails) keeps the settings it started with, so ask it to restart
        // and pick up the new ones. Harmless if no worker is running.
        try {
            Artisan::call('queue:restart');
        } catch (\Throwable $exception) {
            report($exception);
        }

        Notification::make()
            ->title('LiveNet Auth settings saved')
            ->success()
            ->send();
    }
}
