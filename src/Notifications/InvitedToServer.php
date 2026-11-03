<?php

namespace LiveNet\LiveNetAuth\Notifications;

use App\Filament\Server\Pages\Console;
use App\Models\Server;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when someone is added as a subuser.
 *
 * - If they are already linked to LiveNet's login, it is a plain "you were added" email.
 * - If not, it explains how to create a LiveNet account with the same email address,
 *   because the panel's own password login is turned off.
 */
class InvitedToServer extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Server $server,
        public ?string $invitedBy,
        public bool $linked,
    ) {}

    /** @return string[] */
    public function via(): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $name = $this->server->name;

        if ($this->linked) {
            return (new MailMessage())
                ->subject("You were added to {$name}")
                ->greeting('Hello!')
                ->line("You were added as a subuser of the server \"{$name}\".")
                ->action('Open the server', Console::getUrl(panel: 'server', tenant: $this->server));
        }

        $signup = (string) config('livenet-auth.signup_url');
        $signup .= (str_contains($signup, '?') ? '&' : '?') . 'email=' . rawurlencode($notifiable->email);

        $who = $this->invitedBy ? "{$this->invitedBy} invited" : 'You were invited';

        return (new MailMessage())
            ->subject("You've been invited to {$name} on LiveNet")
            ->greeting('Hello!')
            ->line("{$who} you to help manage the server \"{$name}\".")
            ->line("To get access, you need a LiveNet account that uses this same email address: {$notifiable->email}")
            ->action('Create your LiveNet account', $signup)
            ->line('After you confirm your email, sign in to the panel with the LiveNet button and the server will be waiting for you: ' . url('/'))
            ->line('Already have a LiveNet account with this email? Skip the first step and just sign in to the panel.')
            ->line('Use the same email address this message was sent to, or the server will not show up for you.');
    }
}
