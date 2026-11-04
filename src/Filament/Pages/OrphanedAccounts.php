<?php

namespace LiveNet\LiveNetAuth\Filament\Pages;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LiveNet\LiveNetAuth\Support\Orphans;
use Throwable;

/**
 * Admin page listing panel accounts whose LiveNet (Authentik) account no longer exists, with a button to
 * delete each one. It checks Authentik when the page opens (and when "Check Authentik now" is pressed),
 * and only root admins can see it. Accounts that own servers are listed but cannot be deleted here.
 */
class OrphanedAccounts extends Page implements HasTable
{
    use InteractsWithTable;

    private const CACHE_KEY = 'livenet-auth.orphans';

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

    /** Sits in the same menu group as Pelican's own Users page, right after it. */
    public static function getNavigationGroup(): ?string
    {
        $users = self::usersResource();

        try {
            $group = $users ? $users::getNavigationGroup() : null;
        } catch (Throwable) {
            $group = null;
        }

        return is_string($group) && $group !== '' ? $group : 'User';
    }

    public static function getNavigationSort(): ?int
    {
        $users = self::usersResource();

        try {
            $sort = $users ? $users::getNavigationSort() : null;
        } catch (Throwable) {
            $sort = null;
        }

        return is_int($sort) ? $sort + 1 : null;
    }

    /** @return class-string|null */
    private static function usersResource(): ?string
    {
        foreach (['App\\Filament\\Admin\\Resources\\Users\\UserResource', 'App\\Filament\\Admin\\Resources\\UserResource'] as $class) {
            if (class_exists($class)) {
                return $class;
            }
        }

        return null;
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
            // In the table's toolbar (top right, where a search box would be), like a refresh button should be.
            ->headerActions([
                Action::make('refresh')
                    ->label('Refresh')
                    ->icon('tabler-refresh')
                    ->color('gray')
                    ->action(function (): void {
                        Cache::forget(self::CACHE_KEY);
                        $this->resetTable();
                    }),
            ])
            ->recordActions([
                Action::make('delete')
                    ->label('Delete')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete this panel account?')
                    ->modalDescription('Its LiveNet account no longer exists, so nobody can sign in to it. Deleting it stops a future account with the same email from being linked to it.')
                    ->visible(fn (array $record): bool => !$record['owns'])
                    ->action(fn (array $record) => $this->deleteAccount((int) $record['id'])),
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
            Log::warning('LiveNet Auth orphan check stopped: ' . $exception->getMessage());

            return [];
        }

        return $found;
    }

    private function deleteAccount(int $id): void
    {
        // Check again with Authentik right now: never delete on an old answer.
        Cache::forget(self::CACHE_KEY);

        try {
            $match = collect(Orphans::scan())->first(fn (array $row): bool => $row['user']->id === $id);

            if (!$match || $match['owns']) {
                Notification::make()->title('That account can\'t be deleted')->body('It is no longer orphaned, or it owns servers.')->warning()->send();

                return;
            }

            /** @var User $user */
            $user = $match['user'];
            $label = "{$user->username} <{$user->email}>";
            $user->delete();

            Log::warning("LiveNet Auth deleted orphaned panel account {$label} from the admin page.");
            Notification::make()->title('Account deleted')->body($label)->success()->send();
        } catch (Throwable $exception) {
            Notification::make()->title('Could not delete the account')->body($exception->getMessage())->danger()->send();
        }

        Cache::forget(self::CACHE_KEY);
        $this->resetTable();
    }
}
