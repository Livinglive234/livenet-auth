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

    // Email people who are added as a subuser, with a link to create a LiveNet account
    // if they don't have one yet. Also set PANEL_SEND_ADDED_TO_SERVER_NOTIFICATION=false
    // so they don't get Pelican's own email as well.
    'invite_emails' => (bool) env('LIVENET_AUTH_INVITE_EMAILS', true),

    // Where "Create your LiveNet account" in that email goes (Authentik's signup flow).
    'signup_url' => env('LIVENET_AUTH_SIGNUP_URL', 'https://auth.livenet.host/if/flow/default-enrollment-flow/'),

    // Skip the login page and go straight to Authentik. Visit /login?stay to see the page anyway.
    'skip_login_page' => (bool) env('LIVENET_AUTH_SKIP_LOGIN', false),
];
