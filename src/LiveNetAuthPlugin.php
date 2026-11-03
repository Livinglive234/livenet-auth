<?php

namespace LiveNet\LiveNetAuth;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Section;

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
        'mfa_setup_url' => ['LIVENET_AUTH_MFA_SETUP_URL', 'livenet-auth.mfa_setup_url', 'string'],
        'mfa_recovery_codes_url' => ['LIVENET_AUTH_MFA_RECOVERY_CODES_URL', 'livenet-auth.mfa_recovery_codes_url', 'string'],
        'reset_email_to_authentik' => ['LIVENET_AUTH_RESET_EMAIL_TO_AUTHENTIK', 'livenet-auth.reset_email_to_authentik', 'bool'],
        'logout_to_authentik' => ['LIVENET_AUTH_LOGOUT_TO_AUTHENTIK', 'livenet-auth.logout_to_authentik', 'bool'],
        'logout_url' => ['LIVENET_AUTH_LOGOUT_URL', 'livenet-auth.logout_url', 'string'],
        'logout_end_session_url' => ['LIVENET_AUTH_LOGOUT_END_SESSION_URL', 'livenet-auth.logout_end_session_url', 'string'],
        'logout_return_url' => ['LIVENET_AUTH_LOGOUT_RETURN_URL', 'livenet-auth.logout_return_url', 'string'],
        'skip_login_page' => ['LIVENET_AUTH_SKIP_LOGIN', 'livenet-auth.skip_login_page', 'bool'],

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
        // Authentik handles sign-in now, so drop the panel's own two-factor options. They are
        // only ever asked for after a password sign-in, which is turned off. This also removes
        // the 2FA tab on the profile page. A failure here must never break the panel.
        if (config('livenet-auth.disable_panel_2fa', env('LIVENET_AUTH_DISABLE_PANEL_2FA', true))) {
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
            $data[$key] = $type === 'bool' ? (bool) $value : (string) ($value ?? '');
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
                        ->label('Remove the panel\'s own 2FA')
                        ->helperText('Removes the authenticator-app and email-code options and the 2FA tab, and forces "require 2FA" off so nobody gets locked out. Takes effect after the page reloads.'),
                ]),

            Section::make('Two-factor authentication')
                ->description('Shown on every profile page once the panel\'s own 2FA is removed.')
                ->schema([
                    Toggle::make('show_mfa_notice')
                        ->label('Show a notice that 2FA is managed by the LiveNet account'),
                    TextInput::make('mfa_setup_url')
                        ->label('Authenticator app setup address')
                        ->helperText('Authentik\'s authenticator setup flow.')
                        ->url()
                        ->required()
                        ->maxLength(255),
                    TextInput::make('mfa_recovery_codes_url')
                        ->label('Backup codes address')
                        ->helperText('Authentik\'s recovery codes (static tokens) setup flow.')
                        ->url()
                        ->required()
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
                    Toggle::make('logout_to_authentik')
                        ->label('Signing out also ends the Authentik login'),
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
                        ->helperText('Leave empty to use the panel\'s /login.')
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

            $values[$envName] = $type === 'bool' ? ($value ? 'true' : 'false') : trim((string) $value);
        }

        $this->writeToEnvironment($values);

        Notification::make()
            ->title('LiveNet Auth settings saved')
            ->success()
            ->send();
    }
}
