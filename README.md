# LiveNet Auth (Pelican plugin)

Makes Authentik the one login for the LiveNet panel.

What it does:

1. **Keeps the Authentik link in place.** A user can no longer remove their Authentik link from the profile page (the button is hidden, and the save is blocked on the server). Console commands (`artisan`, `tinker`) are never blocked.
2. **Locks synced accounts.** Anyone linked to Authentik becomes "managed externally" (username, email and password are locked in the panel). Root admins are skipped.
3. **Bridges subuser invites.** When someone is added as a subuser, they get an email. If they have no LiveNet login yet, it explains how to create one with the same email address, then how to sign in to the panel.
4. **Keeps usernames in step.** Each time someone signs in with Authentik, the panel account's username is set to their Authentik username. This fixes the placeholder name an invited person gets (like `kai3x9`) once they create their LiveNet account. Root admins are skipped, and a name that's already taken is left alone (it's written to the panel's log).
5. **Optionally skips the login page** and goes straight to Authentik.
6. **Sends "forgot password" to Authentik's recovery flow**, since a panel password can no longer be used to sign in.
7. **Hides the passkey buttons** so nobody sets one up (optionally blocks passkey sign-in completely).
8. **Sends the panel's 2FA tab to the LiveNet account.** The tab stays exactly as Pelican draws it, and clicking it (or opening the old `?tab=2fa` address) goes to the website's 2FA page. It forces `APP_2FA_REQUIRED` to 0 so nobody is locked out. You can instead remove the tab entirely and show a plain button.
9. **Cleans up deleted accounts.** Once a day it removes panel accounts whose Authentik account no longer exists (read-only token, reports first, never touches admins or accounts that own servers).
10. **Replaces the panel's password reset email** (including the admin Users page's "Reset password" button). Someone already linked to a LiveNet login gets a link to Authentik's recovery flow. Someone who used the panel before LiveNet logins existed gets an email telling them to create a LiveNet account with the same email address, so their servers are matched to them.
11. **Signs you out of Authentik too** when you sign out of the panel, then lands on the panel's normal login screen (`/login?stay`, so the login-skip doesn't bounce you straight back to Authentik). On sign-in it keeps your Authentik login token in the panel's server-side session so it can do this. If that is turned off, sign-out still lands on the login screen, but your Authentik login stays active.
13. **"Back to livenet.host" button** under the panel's login form (`LIVENET_AUTH_BACK_TO_SITE`, `_URL`, `_LABEL`). Untested on a live panel.
14. **SFTP password and popup.** Wings checks SFTP logins against the account's panel password, which Authentik users do not have. Every SFTP button (files page, server settings page) now opens a popup in the delete-popup style, in green: with no SFTP password yet it asks you to choose one (min 12 characters); after that it offers **Connect to SFTP**, **Change SFTP password** and **Cancel**. The password is stored as the account's panel password (password login stays off) and a small table, `livenet_auth_sftp_passwords`, remembers who has set one. SSH keys keep working. **Untested on a live panel.**
12. **Signs you out of the panel when your Authentik login ends elsewhere** (back-channel logout). Authentik POSTs a signed notice to `/livenet-auth/backchannel-logout`; the plugin checks the signature, remembers the time, and ends any panel session that began earlier (the next page load or click sends you to the login screen). Only sessions started with Authentik are affected, so a break-glass password login is left alone. Sessions started before this was installed are not covered until the next sign-in. In Authentik, on the panel's provider set **Logout URI** to `https://panel.livenet.host/livenet-auth/backchannel-logout` and **Logout Method** to **Back-channel**. Verified only by reading the spec and testing the same algorithm in JavaScript; **untested on a live panel**.

**Status:** tested on a live panel (imported as a zip through Admin → Plugins):

| Feature | Status |
|---|---|
| Keep the Authentik link | works |
| Lock synced accounts | works |
| Subuser invite email | works |
| Skip the login page | works |
| Forgot-password redirect | **untested** |
| Sign out also ends the Authentik login, then returns to the panel's login (off by default) | works (v1.4.1) |
| Sign out ends the Authentik login and lands on /login?stay | **untested** |
| Settings page | **untested** |
| Replaced reset email (both wordings) | **untested** |
| Daily cleanup of deleted accounts | **untested** |
| Hide passkey buttons | **untested** |
| Block passkey routes (off by default) | **untested** |
| Remove panel 2FA | **untested** |
| 2FA tab click goes to the website's 2FA page | **untested** |
| Username sync | **untested**. It relies on Socialite remembering the user fetched earlier in the same request. |

It was written without PHP available, so run the lint step below before enabling it. Install it by importing the zip in Admin → Plugins; copying the folder by hand did not work reliably.

## Install

1. Copy the plugin folder to the panel: `/var/www/pelican/plugins/livenet-auth/`
2. Check the code compiles (this prints "No syntax errors" for each file):

   ```bash
   cd /var/www/pelican/plugins/livenet-auth && find . -name '*.php' -exec php -l {} \;
   ```
3. In the panel: Admin → Plugins → import or enable it. (Or `php artisan p:plugin:install`.)
4. Add the settings below to the panel's `.env`, then:

   ```bash
   cd /var/www/pelican && sudo -u www-data php artisan optimize:clear
   ```

## Cleaning up deleted accounts

1. In Authentik, create a **service account** (Directory, Users) and give it only permission to view users (for example through a role with "Can view user", assigned to a group the account belongs to). Then create an **API token** for it (Directory, Tokens and App passwords, intent "API Token"). Exact screens differ a little between Authentik versions.
2. In the plugin's Settings, paste the token, turn on **Run the daily check**, and leave **Delete** off.
3. See what it would do: `cd /var/www/pelican && sudo -u www-data php artisan livenet-auth:reconcile --dry-run`
4. When the report looks right, turn on **Delete the accounts it finds**.

It matches by email or username, and does nothing if Authentik doesn't answer clearly.

### The "Orphaned accounts" page

Once the token is set (the daily check does not need to be on), **Admin → Orphaned accounts** (root admins only) lists every panel account whose LiveNet account no longer exists, with a **Delete** button on each. It checks Authentik when the page opens, or when you press **Check Authentik now**, and checks again before deleting. Accounts that own servers are listed as "Owns servers" and have no Delete button: move or delete their servers first.

## Settings page

Admin → Plugins → LiveNet Auth → Settings has a toggle or field for everything below, and for Pelican's own related settings (password login, the two email notifications). Saving writes to the panel's `.env` and clears the config cache, so changes apply straight away. You can still edit `.env` by hand if you prefer.

Status: **untested** (written without PHP available).

## Settings (all optional, in `.env`)

| Setting | Default | What it does |
|---|---|---|
| `LIVENET_AUTH_ENABLED` | `true` | Master switch. `false` turns everything off. |
| `LIVENET_AUTH_PROVIDER` | `authentik` | The OAuth provider id in the panel's OAuth settings. |
| `LIVENET_AUTH_PROTECT_LINK` | `true` | Keep the Authentik link in place. |
| `LIVENET_AUTH_LOCK_SYNCED_USERS` | `true` | Mark linked non-admins as managed externally. |
| `LIVENET_AUTH_SYNC_USERNAME` | `true` | Copy the Authentik username onto the panel account at sign-in. |
| `LIVENET_AUTH_INVITE_EMAILS` | `true` | Send the invite email described above. |
| `LIVENET_AUTH_SIGNUP_URL` | `https://auth.livenet.host/if/flow/default-enrollment-flow/` | Where "Create your LiveNet account" goes. `?email=` is added. |
| `LIVENET_AUTH_REDIRECT_PASSWORD_RESET` | `true` | Send the panel's forgot-password page to Authentik. |
| `LIVENET_AUTH_RECOVERY_URL` | `https://auth.livenet.host/if/flow/default-recovery-flow/` | Authentik's recovery flow. Check this matches your flow's slug. |
| `LIVENET_AUTH_HIDE_PASSKEYS` | `true` | Hide the passkey buttons (login page and profile tab). Cosmetic only. |
| `LIVENET_AUTH_BLOCK_PASSKEYS` | `false` | Also block the passkey routes completely. |
| `LIVENET_AUTH_DISABLE_PANEL_2FA` | `false` | Remove the 2FA tab and the panel's own 2FA options (a plain button is shown instead). |
| `LIVENET_AUTH_SHOW_MFA_NOTICE` | `true` | Send the 2FA tab's click, and the old tab address, to the website's 2FA page. |
| `LIVENET_AUTH_MFA_PAGE_URL` | `https://livenet.host/account/2fa` | The website page for setting up 2FA. |
| `LIVENET_AUTH_RECONCILE_ENABLED` | `false` | Run the daily cleanup of accounts deleted in Authentik. |
| `LIVENET_AUTH_RECONCILE_DELETE` | `false` | Delete what it finds. Off = report only. |
| `LIVENET_AUTH_RECONCILE_MAX_DELETES` | `3` | Refuse to delete more than this many in one run. |
| `LIVENET_AUTH_AUTHENTIK_URL` | `https://auth.livenet.host` | Where Authentik is. |
| `LIVENET_AUTH_AUTHENTIK_API_TOKEN` | empty | Read-only Authentik token (a secret). |
| `LIVENET_AUTH_RESET_EMAIL_TO_AUTHENTIK` | `true` | Replace Pelican's reset email with one linking to Authentik's recovery flow. |
| `LIVENET_AUTH_LOGOUT_SHOW_LOGIN_PAGE` | `true` | If the toggle below is off: still land on the login screen, with the Authentik login left active. |
| `LIVENET_AUTH_LOGOUT_TO_AUTHENTIK` | `true` | Panel sign-out also ends the Authentik login. |
| `LIVENET_AUTH_BACKCHANNEL_LOGOUT` | `true` | End panel sessions when Authentik reports the login ended elsewhere. |
| `LIVENET_AUTH_BACKCHANNEL_ISSUER` / `_CLIENT_ID` / `_CLIENT_SECRET` | empty | Normally worked out from the end-session address and the panel's OAuth settings. Set only if the notice is rejected. |
| `LIVENET_AUTH_LOGOUT_URL` | `https://auth.livenet.host/flows/-/default/invalidation/` | Where to go after panel sign-out. |
| `LIVENET_AUTH_LOGOUT_END_SESSION_URL` | `https://auth.livenet.host/application/o/pelican/end-session/` | The panel app's end-session address in Authentik (check the slug). Empty uses the general logout page only. |
| `LIVENET_AUTH_LOGOUT_RETURN_URL` | the panel's `/login?stay` | Where Authentik sends people after logout. Must be a "Logout" Redirect URI on the provider. |
| `LIVENET_AUTH_SKIP_LOGIN` | `false` | Go straight to Authentik from the login page. Add `?stay` to the URL to see the page. |

Also set these panel settings, so Pelican doesn't send its own emails too:

```
PANEL_SEND_ACCOUNT_CREATED_NOTIFICATION=false
PANEL_SEND_ADDED_TO_SERVER_NOTIFICATION=false
```

The first stops the "set a password" email that new accounts get. The second is replaced by this plugin's invite email.

## Turning the panel's password login off

Pelican has this built in, no plugin needed:

```
AUTH_DISABLE_PASSWORD_LOGIN=true
```

Do this **last**, after you have checked that signing in with the LiveNet button works for a test user and for your own admin account. It applies to everyone, admins included. To undo it, change the value to `false` over SSH and run `php artisan optimize:clear`.

## Rolling back

- Set `LIVENET_AUTH_ENABLED=false` and run `php artisan optimize:clear`, or
- disable or delete the plugin in the admin area.

## Limits

- The invited person must use **the same email address** the invite was sent to. If they sign up with a different one, they get a separate panel account with no server access, and the owner has to invite that address.
- Pelican creates a panel account for any email a server owner types in. This plugin doesn't change that.
- Hiding the link button is a CSS rule that matches the button's internal name. If a Pelican update renames it, the button may reappear. The server-side check keeps working either way.

## Releasing and updating

The panel checks `update_url` in `plugin.json`, which points at `update.json` in this repo. That file says the newest version, where to download the zip, and its checksum. The panel caches it for about 10 minutes.

To publish a new version, use the local `publish.sh` script. It is deliberately not in this repository (it is listed in `.gitignore`), so it only exists on the computer that publishes. From that clone, with your changes already committed and pushed to `main`:

```bash
./publish.sh 1.4.1
```

It checks you're on `main` with nothing uncommitted and in sync with GitHub, asks before publishing, then bumps the version, builds `livenet-auth.zip`, writes `update.json` with the checksum, tags the commit, pushes, and creates a GitHub release with the zip attached. The panel then shows an update on its Plugins page, and you can update in place.

The first install must come from a version that already has `update_url` in its `plugin.json` (1.4.0 and later). `./release.sh <version>` (in this repository) does just the build step, if you want to make a zip without publishing. To recreate `publish.sh` on another computer, it only needs to run `release.sh`, commit, tag and push, then `gh release create` with the zip.
