<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

class PasswordResetService
{
    /**
     * Status returned when the account exists but the mail transport failed.
     *
     * Kept distinct from the broker's own statuses so the failure is auditable,
     * while the controller still renders the same generic response.
     */
    public const SEND_FAILED = 'passwords.send_failed';

    /**
     * Minimum wall time for a forgot-password request, in microseconds.
     *
     * The broker already pads its own lookup to 200 ms; this outer floor also
     * covers the activity write, which only happens for accounts that exist.
     */
    private const SEND_TIMEBOX_MICROSECONDS = 400_000;

    public function __construct(
        protected ActivityService $activityService
    ) {}

    /**
     * Email a reset link if the address belongs to an account.
     *
     * Every outcome — sent, unknown address, throttled, or transport failure —
     * is reported to the caller identically. Anything that varies by account
     * existence, including the HTTP status and the response time, turns this
     * endpoint into an account-existence oracle.
     *
     * OAuth-only accounts (password is null) are deliberately included: they
     * receive a link and can set a first password. Refusing them would leak
     * which addresses are Google/GitHub-backed.
     */
    public function sendResetLink(string $email, ?Request $request = null): string
    {
        return (new Timebox)->call(
            function () use ($email, $request): string {
                // Mail is sent synchronously inside the broker, so a transport
                // error would otherwise escape as a 500 — and only ever for a
                // registered address, which is exactly the leak this guards.
                try {
                    $status = Password::sendResetLink(['email' => $email]);
                } catch (TransportExceptionInterface|Throwable $exception) {
                    report($exception);
                    $status = self::SEND_FAILED;
                }

                $user = User::where('email', $email)->first();

                if ($user) {
                    $this->activityService->log(
                        userId: $user->id,
                        type: 'password_reset_requested',
                        description: 'Requested a password reset link',
                        metadata: ['status' => $status],
                        request: $request
                    );
                }

                return $status;
            },
            self::SEND_TIMEBOX_MICROSECONDS
        );
    }

    /**
     * Complete a reset. Returns the broker status string.
     *
     * On success every Sanctum token is revoked, including the caller's. A reset
     * is the flow used when an account may already be compromised, so it is
     * deliberately stricter than changePassword, which preserves the current
     * session.
     */
    public function reset(array $credentials, ?Request $request = null): string
    {
        $resetUser = null;

        $status = Password::reset(
            $credentials,
            function (User $user, string $password) use (&$resetUser): void {
                $user->forceFill([
                    // The 'password' => 'hashed' cast hashes this. Calling
                    // Hash::make here would double-hash and break login.
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();

                $resetUser = $user;

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET && $resetUser) {
            $this->activityService->log(
                userId: $resetUser->id,
                type: 'password_reset_completed',
                description: 'Completed a password reset and signed out all sessions',
                request: $request
            );
        }

        return $status;
    }
}
