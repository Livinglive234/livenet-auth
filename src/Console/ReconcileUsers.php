<?php

namespace LiveNet\LiveNetAuth\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use LiveNet\LiveNetAuth\Support\Orphans;
use Throwable;

/**
 * Finds panel accounts whose LiveNet (Authentik) account no longer exists, and removes them.
 *
 * Why: panel accounts are matched to Authentik by email. If someone's Authentik account is
 * deleted but their panel account stays, a stranger who later signs up with that email would be
 * linked to the old account and could reach its servers.
 *
 * It only ever uses a read-only Authentik token. It reports first, and only deletes when
 * LIVENET_AUTH_RECONCILE_DELETE is on.
 */
class ReconcileUsers extends Command
{
    protected $signature = 'livenet-auth:reconcile {--dry-run : Only report, even if deleting is turned on}';

    protected $description = 'Remove panel accounts whose LiveNet (Authentik) account has been deleted.';

    public function handle(): int
    {
        $delete = config('livenet-auth.reconcile_delete') && !$this->option('dry-run');
        $max = max(1, (int) config('livenet-auth.reconcile_max_deletes'));

        // 1. Panel accounts linked to Authentik that Authentik no longer knows. If Authentik cannot give a
        //    clear answer, stop: never act on a bad answer.
        try {
            $orphans = Orphans::scan();
        } catch (Throwable $exception) {
            $this->error('Could not get a clear answer from Authentik, so nothing was changed: ' . $exception->getMessage());
            Log::warning('LiveNet Auth cleanup stopped: ' . $exception->getMessage());

            return self::FAILURE;
        }

        if (!$orphans) {
            $this->info('Every Authentik-linked panel account still exists in Authentik. Nothing to do.');

            return self::SUCCESS;
        }

        // 2. Accounts that own servers cannot be deleted by Pelican. List them for a person to handle.
        $removable = [];
        foreach ($orphans as ['user' => $user, 'owns' => $owns]) {
            $this->line(sprintf('%s  %s <%s>%s', $owns ? 'NEEDS ATTENTION' : 'orphan', $user->username, $user->email, $owns ? '  (owns servers)' : ''));
            if (!$owns) {
                $removable[] = $user;
            }
        }

        Log::warning('LiveNet Auth cleanup found ' . count($orphans) . ' orphaned panel account(s), ' . count($removable) . ' removable.');

        if (!$removable) {
            $this->warn('Nothing can be removed automatically.');

            return self::SUCCESS;
        }

        if (!$delete) {
            $this->warn('Report only: nothing was deleted. Turn on "delete" in the plugin settings to let this remove them.');

            return self::SUCCESS;
        }

        // 4. A mistake must never clear out many people at once.
        if (count($removable) > $max) {
            $this->error(sprintf('Refusing to delete %d accounts in one run (the limit is %d). Check the list above first.', count($removable), $max));
            Log::warning('LiveNet Auth cleanup refused: too many accounts to delete at once.');

            return self::FAILURE;
        }

        foreach ($removable as $user) {
            try {
                $user->delete();
                $this->info("Deleted {$user->username} <{$user->email}>");
                Log::warning("LiveNet Auth cleanup deleted panel account {$user->username} <{$user->email}>.");
            } catch (Throwable $exception) {
                $this->error("Could not delete {$user->username}: " . $exception->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
