<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Email address verification.
 *
 * Replaces Laravel's built-in VerifyEmail notification so the link points at a
 * signed API route rather than a `verification.verify` web route this API does
 * not serve. The API validates the signature and then redirects the browser to
 * the SPA, which keeps Laravel's signed-URL check intact — the SPA cannot
 * validate a signature itself without leaking the app key.
 */
class VerifyEmailNotification extends Notification
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expiresInMinutes = (int) config('auth.verification.expire', 60);

        return (new MailMessage)
            ->subject('Verify your '.config('app.name').' email address')
            ->greeting('Confirm your email address')
            ->line('Please confirm this is your email address to finish setting up your account.')
            ->action('Verify email address', $this->verificationUrl($notifiable))
            ->line("This link expires in {$expiresInMinutes} minutes.")
            ->line('If you did not create an account, you can ignore this email.');
    }

    /**
     * Build the temporary signed URL for the API verification route.
     */
    private function verificationUrl(object $notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes((int) config('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );
    }
}
