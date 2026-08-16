<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(
        protected EmailVerificationService $verificationService
    ) {}

    // POST /api/v1/auth/email/resend

    /**
     * Resend the verification email
     *
     * Sends a fresh verification link to the authenticated user's address.
     * Returns 200 with `sent: false` when the address is already verified, so a
     * client can render the right state without treating it as an error.
     *
     * @authenticated
     *
     * @group Authentication
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Verification email sent.",
     *   "data": {
     *     "sent": true,
     *     "verified": false
     *   }
     * }
     * @response 200 scenario="Already verified" {
     *   "success": true,
     *   "message": "This email address is already verified.",
     *   "data": {
     *     "sent": false,
     *     "verified": true
     *   }
     * }
     * @response 429 {
     *   "message": "Too Many Attempts."
     * }
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();
        $sent = $this->verificationService->send($user, $request);

        return $this->successResponse(
            data: [
                'sent' => $sent,
                'verified' => $user->hasVerifiedEmail(),
            ],
            message: $sent
                ? 'Verification email sent.'
                : ($user->hasVerifiedEmail()
                    ? 'This email address is already verified.'
                    : 'Verification email could not be sent. Please try again shortly.')
        );
    }

    // GET /api/v1/auth/email/verify/{id}/{hash}

    /**
     * Verify an email address
     *
     * Target of the link in the verification email. The route is signed, so the
     * `signed` middleware rejects tampered or expired links before this runs.
     *
     * This endpoint is opened by a mail client, not by the SPA, so it redirects
     * to `FRONTEND_URL/email-verified?status=...` rather than returning JSON.
     * Status values are `verified`, `already-verified`, and `invalid`.
     *
     * @unauthenticated
     *
     * @group Authentication
     *
     * @urlParam id integer required The user id from the emailed link. Example: 1
     * @urlParam hash string required The address hash from the emailed link. Example: 3a5f1c...
     *
     * @response 302 scenario="Redirects to the SPA" {}
     */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::find($id);

        if (! $user) {
            return $this->redirectToFrontend('invalid');
        }

        $alreadyVerified = $user->hasVerifiedEmail();

        if (! $this->verificationService->verify($user, $hash, $request)) {
            return $this->redirectToFrontend('invalid');
        }

        return $this->redirectToFrontend($alreadyVerified ? 'already-verified' : 'verified');
    }

    private function redirectToFrontend(string $status): RedirectResponse
    {
        return redirect()->away(
            config('app.frontend_url').'/email-verified?status='.$status
        );
    }
}
