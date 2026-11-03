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
                ->subject('Action needed: LiveNet has a new account system')
                ->greeting('Important: our account system has changed')
                ->line('LiveNet Server Hosting has moved to a new account system. The old panel passwords no longer work, so a password reset is not possible for your account.')
                ->line("**To keep access to your servers, you must create a new LiveNet account using this same email address: {$notifiable->email}**")
                ->line('**If you sign up with a different email address, you will lose access to your servers.** Your servers are matched to your new account by email address only.')
                ->action('Create your LiveNet account', $signup)
                ->line('After you confirm your email, sign in to the panel with the LiveNet button. Your servers will be there.')
                ->line('Already created a LiveNet account with this email address? Then you do not need to do anything else. Just sign in to the panel with the LiveNet button.');
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
