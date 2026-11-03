<?php

namespace LiveNet\LiveNetAuth\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Replaces Pelican's own password reset email. A panel password can no longer be used to
 * sign in, so:
 *
 * - someone already linked to a LiveNet login is sent to Authentik's recovery flow;
 * - someone who used the panel before LiveNet logins existed has no login to recover, so they
 *   are told to create one with the same email address (their servers are matched by email).
 */
class AuthentikPasswordReset extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $recoveryUrl,
        public bool $linked = true,
        public string $signupUrl = '',
    ) {}

    /** @return string[] */
    public function via(): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        if (!$this->linked && $this->signupUrl !== '') {
            $signup = $this->signupUrl . (str_contains($this->signupUrl, '?') ? '&' : '?') . 'email=' . rawurlencode($notifiable->email);

            return (new MailMessage())
                ->subject('Your LiveNet panel account now uses a LiveNet login')
                ->greeting('Hello!')
                ->line('The panel no longer uses passwords. You sign in with a LiveNet account instead.')
                ->line("To keep your servers, create a LiveNet account that uses this same email address: {$notifiable->email}")
                ->action('Create your LiveNet account', $signup)
                ->line('After you confirm your email, sign in to the panel with the LiveNet button. Your servers will be there.')
                ->line('Already made a LiveNet account with this email? Skip the first step and just sign in to the panel.')
                ->line("Forgot your LiveNet password? Reset it here: {$this->recoveryUrl}")
                ->line('Use the same email address this message was sent to, or your servers will not show up.');
        }

        return (new MailMessage())
            ->subject('Reset your LiveNet password')
            ->greeting('Hello!')
            ->line('A password reset was requested for your LiveNet account.')
            ->line('Your password is kept with your LiveNet account, so you reset it there.')
            ->action('Reset your password', $this->recoveryUrl)
            ->line('If you did not ask for this, you can ignore this email.');
    }
}
