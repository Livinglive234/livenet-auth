<?php

namespace LiveNet\LiveNetAuth\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Replaces Pelican's own password reset email. A panel password can no longer be used
 * to sign in, so this points people at Authentik's recovery flow instead.
 */
class AuthentikPasswordReset extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $recoveryUrl) {}

    /** @return string[] */
    public function via(): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Reset your LiveNet password')
            ->greeting('Hello!')
            ->line('A password reset was requested for your LiveNet account.')
            ->line('Your password is kept with your LiveNet account, so you reset it there.')
            ->action('Reset your password', $this->recoveryUrl)
            ->line('If you did not ask for this, you can ignore this email.');
    }
}
