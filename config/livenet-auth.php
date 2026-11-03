<?php

// Every setting can be changed in the panel's .env file. Nothing here is a secret.
return [
    // Master switch. Set to false to turn the whole plugin off without removing it.
    'enabled' => (bool) env('LIVENET_AUTH_ENABLED', true),

    // The OAuth provider id Authentik was added as in the panel's OAuth settings.
    'provider' => env('LIVENET_AUTH_PROVIDER', 'authentik'),

    // Stop users from removing their Authentik link (and hide the button for it).
    // Console commands (artisan, tinker) are never blocked, so an admin can still fix a link.
    'protect_link' => (bool) env('LIVENET_AUTH_PROTECT_LINK', true),

    // Mark everyone linked to Authentik as "managed externally" (locks their username,
    // email and password in the panel). Root admins are skipped so they can still fix things.
    'lock_synced_users' => (bool) env('LIVENET_AUTH_LOCK_SYNCED_USERS', true),

    // Copy the Authentik username onto the panel account every time someone signs in with
    // Authentik. This fixes the placeholder name an invited person gets (like "kai3x9")
    // once they create their LiveNet account. Root admins are skipped.
    'sync_username' => (bool) env('LIVENET_AUTH_SYNC_USERNAME', true),

    // Email people who are added as a subuser, with a link to create a LiveNet account
    // if they don't have one yet. Also set PANEL_SEND_ADDED_TO_SERVER_NOTIFICATION=false
    // so they don't get Pelican's own email as well.
    'invite_emails' => (bool) env('LIVENET_AUTH_INVITE_EMAILS', true),

    // Where "Create your LiveNet account" in that email goes (Authentik's signup flow).
    'signup_url' => env('LIVENET_AUTH_SIGNUP_URL', 'https://auth.livenet.host/if/flow/default-enrollment-flow/'),

    // Send the panel's "forgot password" page to Authentik's recovery flow, since a panel
    // password can no longer be used to sign in.
    'redirect_password_reset' => (bool) env('LIVENET_AUTH_REDIRECT_PASSWORD_RESET', true),

    // Authentik's password recovery flow. Click "Forgot password?" on Authentik's login page
    // and copy the address if this guess is wrong.
    'recovery_url' => env('LIVENET_AUTH_RECOVERY_URL', 'https://auth.livenet.host/if/flow/default-recovery-flow/'),

    // Hide every passkey button (the login page's and the profile page's passkey tab), so people
    // never set one up. This only hides the buttons. The passkey routes still work, so anyone who
    // already has a passkey, or finds the endpoints, can still use one.
    'hide_passkeys' => (bool) env('LIVENET_AUTH_HIDE_PASSKEYS', true),

    // Also block the passkey routes completely (404), so passkeys cannot be used at all.
    'block_passkeys' => (bool) env('LIVENET_AUTH_BLOCK_PASSKEYS', false),

    // Remove the panel's own two-factor options (authenticator app and email codes), since
    // Authentik handles sign-in now. This also forces APP_2FA_REQUIRED to 0, because requiring
    // 2FA with no way to set it up would lock people out.
    'disable_panel_2fa' => (bool) env('LIVENET_AUTH_DISABLE_PANEL_2FA', true),

    // Replace Pelican's own password reset email (including the admin Users page's
    // "Reset password" button) with one that links to Authentik's recovery flow.
    'reset_email_to_authentik' => (bool) env('LIVENET_AUTH_RESET_EMAIL_TO_AUTHENTIK', true),

    // Signing out of the panel also signs you out of Authentik.
    'logout_to_authentik' => (bool) env('LIVENET_AUTH_LOGOUT_TO_AUTHENTIK', true),

    // Where to go after the panel signs someone out. This is Authentik's own logout flow,
    // which ends the Authentik login.
    'logout_url' => env('LIVENET_AUTH_LOGOUT_URL', 'https://auth.livenet.host/flows/-/default/invalidation/'),

    // Authentik's end-session address for the panel's application (the slug is the part
    // after /application/o/). With it, signing out returns you to the panel's login page.
    // Needs the panel's address added as a "Logout" Redirect URI on the provider. Set it to
    // an empty value to use the general logout page above instead.
    'logout_end_session_url' => env('LIVENET_AUTH_LOGOUT_END_SESSION_URL', 'https://auth.livenet.host/application/o/pelican/end-session/'),

    // Where Authentik sends people after that logout. Defaults to the panel's /login.
    'logout_return_url' => env('LIVENET_AUTH_LOGOUT_RETURN_URL'),

    // Skip the login page and go straight to Authentik. Visit /login?stay to see the page anyway.
    'skip_login_page' => (bool) env('LIVENET_AUTH_SKIP_LOGIN', false),
];
