<?php

namespace LiveNet\LiveNetAuth\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        $base = rtrim((string) config('livenet-auth.authentik_url'), '/');
        $token = (string) config('livenet-auth.authentik_api_token');
        $provider = (string) config('livenet-auth.provider');
        $delete = config('livenet-auth.reconcile_delete') && !$this->option('dry-run');
        $max = max(1, (int) config('livenet-auth.reconcile_max_deletes'));

        if ($base === '' || $token === '') {
            $this->error('Set the Authentik address and API token first (Admin -> Plugins -> LiveNet Auth -> Settings).');

            return self::FAILURE;
        }

        // 1. Everyone who exists in Authentik. If this goes wrong in any way, stop: never act on a bad answer.
        try {
            [$emails, $usernames] = $this->authentikPeople($base, $token);
        } catch (Throwable $exception) {
            $this->error('Could not get a clear answer from Authentik, so nothing was changed: ' . $exception->getMessage());
            Log::warning('LiveNet Auth cleanup stopped: ' . $exception->getMessage());

            return self::FAILURE;
        }

        // 2. Panel accounts linked to Authentik that Authentik no longer knows (by email or username).
        $orphans = [];
        foreach (User::query()->get() as $user) {
            $links = is_array($user->oauth) ? $user->oauth : [];
            if (!isset($links[$provider]) || $user->isRootAdmin()) {
                continue;
            }

            $knownByEmail = in_array(mb_strtolower((string) $user->email), $emails, true);
            $knownByName = in_array(mb_strtolower((string) $user->username), $usernames, true);

            if (!$knownByEmail && !$knownByName) {
                $orphans[] = $user;
            }
        }

        if (!$orphans) {
            $this->info('Every Authentik-linked panel account still exists in Authentik. Nothing to do.');

            return self::SUCCESS;
        }

        // 3. Accounts that own servers cannot be deleted by Pelican. List them for a person to handle.
        $removable = [];
        foreach ($orphans as $user) {
            $owns = $user->servers()->exists();
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

    /**
     * Every email and username Authentik has (including deactivated accounts).
     *
     * @return array{0: string[], 1: string[]}
     */
    private function authentikPeople(string $base, string $token): array
    {
        $emails = [];
        $usernames = [];
        $page = 1;

        for ($guard = 0; $guard < 200; $guard++) {
            $response = Http::withToken($token)->acceptJson()->timeout(15)
                ->get($base . '/api/v3/core/users/', ['page' => $page, 'page_size' => 200, 'include_groups' => 'false']);

            if (!$response->successful()) {
                throw new \RuntimeException('Authentik answered HTTP ' . $response->status() . '.');
            }

            $data = $response->json();
            if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
                throw new \RuntimeException('Authentik\'s answer was not in the expected format.');
            }

            foreach ($data['results'] as $person) {
                if (!empty($person['email'])) {
                    $emails[] = mb_strtolower((string) $person['email']);
                }
                if (!empty($person['username'])) {
                    $usernames[] = mb_strtolower((string) $person['username']);
                }
            }

            $next = (int) ($data['pagination']['next'] ?? 0);
            if ($next <= 0) {
                if (!$usernames) {
                    throw new \RuntimeException('Authentik returned no users at all.');
                }

                return [array_values(array_unique($emails)), array_values(array_unique($usernames))];
            }

            $page = $next;
        }

        throw new \RuntimeException('Too many pages from Authentik.');
    }
}
