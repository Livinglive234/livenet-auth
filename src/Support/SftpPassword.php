<?php

namespace LiveNet\LiveNetAuth\Support;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * SFTP logins are checked by the panel against the account's panel password. People sign in
 * through Authentik, so that password is a random one nobody knows. This lets them choose one
 * that is only used for SFTP, and remembers who has.
 */
class SftpPassword
{
    private const TABLE = 'livenet_auth_sftp_passwords';

    public static function isSet(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        try {
            return Schema::hasTable(self::TABLE) && DB::table(self::TABLE)->where('user_id', $user->id)->exists();
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    public static function set(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password): void {
            // Console-style write: the profile page's "managed externally" lock is for the form, not for this.
            $user->forceFill(['password' => Hash::make($password)])->save();

            DB::table(self::TABLE)->updateOrInsert(['user_id' => $user->id], ['set_at' => now()]);
        });

        // The session remembers a copy of the password hash and signs the person out when it changes. Forget
        // the old copy for their own session, so the next request stores the new one and keeps them signed in.
        try {
            if (Auth::check() && (int) Auth::id() === (int) $user->id && session()->isStarted()) {
                session()->forget(array_unique([
                    'password_hash_' . Filament::getAuthGuard(),
                    'password_hash_' . Auth::getDefaultDriver(),
                ]));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
