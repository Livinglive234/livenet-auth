<?php

namespace LiveNet\LiveNetAuth\Filament\Pages;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LiveNet\LiveNetAuth\Support\Orphans;
use Throwable;

/**
 * Admin page listing panel accounts whose LiveNet (Authentik) account no longer exists. Each row links to
 * the user's page and has a Delete button; rows can be ticked and deleted together. It checks Authentik when
 * the page opens (and on Refresh), and only root admins can see it. Accounts that own servers are listed
 * but cannot be selected or deleted here.
 */
class OrphanedAccounts extends Page implements HasTable
{
    use InteractsWithTable;

    private const CACHE_KEY = 'livenet-auth.orphans';

    /** How many orphans there were at the last check, for the menu badge (-1: the check failed). */
    private const COUNT_KEY = 'livenet-auth.orphans.count';

    /** Why the last check failed, shown instead of the list. */
    public ?string $problem = null;

    public static function canAccess(): bool
    {
        return (bool) user()?->isRootAdmin();
    }

    public static function getNavigationLabel(): string
    {
        return 'Orphaned accounts';
    }

    public function getTitle(): string
    {
        return 'Orphaned accounts';
    }

    /** Sits in the same menu group as Pelican's own Users page. */
    public static function getNavigationGroup(): ?string
    {
        $group = self::fromResources('getNavigationGroup', fn ($value): bool => is_string($value) && $value !== '');

        return is_string($group) ? $group : 'User';
    }

    /** Last in that group: after the Users and Roles pages. */
    public static function getNavigationSort(): ?int
    {
        $sorts = [];
        foreach (self::resources() as $resource) {
            try {
                $sort = $resource::getNavigationSort();
            } catch (Throwable) {
                $sort = null;
            }
            if (is_int($sort)) {
                $sorts[] = $sort;
            }
        }

        return ($sorts ? max($sorts) : 99) + 1;
    }

    /**
     * The count shown on the menu tab, like the other admin pages have. It comes from the last check and is
     * kept for ten minutes, so opening admin pages never waits on Authentik every time.
     */
    public static function getNavigationBadge(): ?string
    {
        try {
            $count = Cache::get(self::COUNT_KEY);

            if ($count === null) {
                try {
                    $count = count(Orphans::scan());
                } catch (Throwable) {
                    $count = -1;
                }
                Cache::put(self::COUNT_KEY, $count, $count < 0 ? 300 : 600);
            }
        } catch (Throwable) {
            return null;
        }

        return $count >= 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return (int) Cache::get(self::COUNT_KEY, 0) > 0 ? 'warning' : 'gray';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'tabler-user-x';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->rows())
            ->columns([
                TextColumn::make('username')->label('Username'),
                TextColumn::make('email')->label('Email'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Owns servers' ? 'warning' : 'danger'),
            ])
            // In the table's toolbar (top right, where a search box would be).
            ->headerActions([
                Action::make('refresh')
                    ->label('Refresh')
                    ->icon('tabler-refresh')
                    ->color('primary')
                    ->action(function (): void {
                        $this->forgetChecks();
                        $this->resetTable();
                    }),
            ])
            // Top left of the toolbar, once rows are ticked.
            ->toolbarActions([
                BulkAction::make('delete_selected')
                    ->label('Delete selected')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete the selected accounts?')
                    ->modalDescription('Their LiveNet accounts no longer exist, so nobody can sign in to them. Each one is checked with Authentik again before it is deleted.')
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => $this->deleteAccounts($records->pluck('id')->map(fn ($id): int => (int) $id)->all())),
            ])
            // The whole row (except the tick box and the Delete button) opens the user's page.
            ->recordUrl(fn ($record): string => $this->userUrl((int) $record['id']))
            ->checkIfRecordIsSelectableUsing(fn ($record): bool => !($record['owns'] ?? false))
            ->recordActions([
                Action::make('delete')
                    ->label('Delete')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete this panel account?')
                    ->modalDescription('Its LiveNet account no longer exists, so nobody can sign in to it. Deleting it stops a future account with the same email from being linked to it.')
                    // Always drawn. For an account that owns servers it is greyed out and says why.
                    ->disabled(fn ($record): bool => (bool) ($record['owns'] ?? false))
                    ->tooltip(fn ($record): ?string => ($record['owns'] ?? false) ? 'Owns servers: move or delete its servers first.' : null)
                    ->action(fn ($record) => $this->deleteAccounts([(int) $record['id']])),
            ])
            ->paginated(false)
            ->emptyStateHeading($this->problem ? 'Could not check Authentik' : 'No accounts to clean up')
            ->emptyStateDescription($this->problem ?? 'Every LiveNet-linked panel account still exists in Authentik.');
    }

    /** @return array<int|string, array<string, mixed>> */
    private function rows(): array
    {
        $this->problem = null;

        try {
            $found = Cache::remember(self::CACHE_KEY, 60, function (): array {
                $rows = [];
                foreach (Orphans::scan() as ['user' => $user, 'owns' => $owns]) {
                    $rows[$user->id] = [
                        'id' => $user->id,
                        'username' => $user->username,
                        'email' => $user->email,
                        'owns' => $owns,
                        'status' => $owns ? 'Owns servers' : 'Orphaned',
                    ];
                }

                return $rows;
            });
        } catch (Throwable $exception) {
            $this->problem = $exception->getMessage();
            Cache::put(self::COUNT_KEY, -1, 300);
            Log::warning('LiveNet Auth orphan check stopped: ' . $exception->getMessage());

            return [];
        }

        // Keep the menu badge in step with what this page just showed.
        Cache::put(self::COUNT_KEY, count($found), 600);

        return $found;
    }

    /**
     * Deletes the given accounts, but only the ones that Authentik still says are orphaned and that own no
     * servers: it checks again right now and never acts on an old answer.
     *
     * @param  int[]  $ids
     */
    private function deleteAccounts(array $ids): void
    {
        $this->forgetChecks();

        try {
            $current = collect(Orphans::scan())->keyBy(fn (array $row): int => (int) $row['user']->id);
        } catch (Throwable $exception) {
            Notification::make()->title('Could not check Authentik, so nothing was deleted')->body($exception->getMessage())->danger()->send();

            return;
        }

        $deleted = [];
        $skipped = 0;

        foreach ($ids as $id) {
            $match = $current->get($id);

            if (!$match || $match['owns']) {
                $skipped++;

                continue;
            }

            $user = $match['user'];
            $label = "{$user->username} <{$user->email}>";

            try {
                $user->delete();
                $deleted[] = $label;
                Log::warning("LiveNet Auth deleted orphaned panel account {$label} from the admin page.");
            } catch (Throwable $exception) {
                $skipped++;
                Log::warning("LiveNet Auth could not delete orphaned panel account {$label}: " . $exception->getMessage());
            }
        }

        if ($deleted) {
            Notification::make()
                ->title(count($deleted) === 1 ? 'Account deleted' : count($deleted) . ' accounts deleted')
                ->body(implode("\n", $deleted))
                ->success()
                ->send();
        }

        if ($skipped) {
            Notification::make()
                ->title($skipped === 1 ? '1 account was not deleted' : "{$skipped} accounts were not deleted")
                ->body('It is no longer orphaned, owns servers, or could not be removed.')
                ->warning()
                ->send();
        }

        $this->forgetChecks();
        $this->resetTable();
    }

    private function forgetChecks(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::COUNT_KEY);
    }

    /** The user's page in the admin panel. */
    private function userUrl(int $id): string
    {
        foreach (self::resources() as $resource) {
            if (!str_ends_with($resource, 'UserResource')) {
                continue;
            }

            try {
                return $resource::getUrl('edit', ['record' => $id]);
            } catch (Throwable) {
                // fall through to the plain address
            }
        }

        return url("/admin/users/{$id}/edit");
    }

    /**
     * Pelican's own Users and Roles pages, when they can be found.
     *
     * @return array<int, class-string>
     */
    private static function resources(): array
    {
        $found = [];

        foreach ([
            'App\\Filament\\Admin\\Resources\\Users\\UserResource',
            'App\\Filament\\Admin\\Resources\\UserResource',
            'App\\Filament\\Admin\\Resources\\Roles\\RoleResource',
            'App\\Filament\\Admin\\Resources\\RoleResource',
        ] as $class) {
            if (class_exists($class)) {
                $found[] = $class;
            }
        }

        return $found;
    }

    /** The first usable answer from Pelican's own pages for a navigation setting. */
    private static function fromResources(string $method, callable $usable): mixed
    {
        foreach (self::resources() as $resource) {
            try {
                $value = $resource::$method();
            } catch (Throwable) {
                continue;
            }

            if ($usable($value)) {
                return $value;
            }
        }

        return null;
    }
}
