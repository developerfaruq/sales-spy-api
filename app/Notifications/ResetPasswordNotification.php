<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Password reset email.
 *
 * Replaces Laravel's built-in ResetPassword notification, which links to a
 * `password.reset` web route. This API serves no browser pages, so the link
 * points at the SPA instead and the SPA posts the token back to
 * /api/v1/auth/reset-password.
 */
class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $token
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expiresInMinutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return (new MailMessage)
            ->subject('Reset your '.config('app.name').' password')
            ->greeting('Password reset requested')
            ->line('We received a request to reset the password for this account.')
            ->action('Reset password', $this->resetUrl($notifiable))
            ->line("This link expires in {$expiresInMinutes} minutes and can only be used once.")
            ->line('If you did not request a password reset, no action is needed and your password stays unchanged.');
    }

    /**
     * Build the SPA link carrying the token and the email it was issued for.
     *
     * The broker validates the token against the email, so both are required
     * for the reset to succeed.
     */
    private function resetUrl(object $notifiable): string
    {
        return config('app.frontend_url').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }
}
