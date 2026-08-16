<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

class EmailVerificationService
{
    public function __construct(
        protected ActivityService $activityService
    ) {}

    /**
     * Send a verification email, unless the address is already verified.
     *
     * Returns false when nothing was sent so the caller can respond accurately.
     * A transport failure is reported but never surfaced as a 500: the account
     * already exists at this point, so there is no enumeration concern, but an
     * unhandled mailer exception would still be a confusing 500 for the client.
     */
    public function send(User $user, ?Request $request = null): bool
    {
        if ($user->hasVerifiedEmail()) {
            return false;
        }

        try {
            $user->notify(new VerifyEmailNotification);
        } catch (TransportExceptionInterface|Throwable $exception) {
            report($exception);

            return false;
        }

        $this->activityService->log(
            userId: $user->id,
            type: 'email_verification_sent',
            description: 'Requested an email verification link',
            request: $request
        );

        return true;
    }

    /**
     * Mark the address verified.
     *
     * The signed URL proves the link came from us and has not expired; the hash
     * proves it was issued for this user's current address, so a link stays
     * invalid after the address changes.
     */
    public function verify(User $user, string $hash, ?Request $request = null): bool
    {
        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return false;
        }

        if ($user->hasVerifiedEmail()) {
            return true;
        }

        $user->markEmailAsVerified();

        event(new Verified($user));

        $this->activityService->log(
            userId: $user->id,
            type: 'email_verified',
            description: 'Verified their email address',
            request: $request
        );

        return true;
    }
}
