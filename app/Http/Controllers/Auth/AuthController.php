<?php

namespace App\Http\Controllers\Auth;

use App\Enums\OAuthProviderEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\AuthService;
use App\Services\EmailVerificationService;
use App\Services\PasswordResetService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function __construct(
        protected AuthService $authService,
        protected PasswordResetService $passwordResetService,
        protected EmailVerificationService $verificationService
    ) {}

    // Email & Password Auth

    // POST /api/v1/auth/register

    /**
     * Register a new user
     *
     * Creates a new user account and returns an auth token immediately.
     * The user is assigned the free plan with 50 starter credits.
     *
     * @unauthenticated
     *
     * @group Authentication
     *
     * @bodyParam name string required The user's full name. Example: John Doe
     * @bodyParam email string required A valid, unique email address. Example: john@example.com
     * @bodyParam password string required Min 8 characters. Example: password123
     * @bodyParam password_confirmation string required Must match password. Example: password123
     *
     * @response 201 {
     *   "success": true,
     *   "message": "Account created successfully",
     *   "data": {
     *     "token": "1|xxxxxxxxxxxxxxxxxxxxxxxx",
     *     "user": {
     *       "id": 1,
     *       "name": "John Doe",
     *       "email": "john@example.com",
     *       "plan": "free",
     *       "credits_balance": 50
     *     }
     *   }
     * }
     * @response 422 {
     *   "success": false,
     *   "message": "Validation failed",
     *   "errors": {
     *     "email": ["An account with this email already exists."],
     *     "password": ["Password must be at least 8 characters."]
     *   }
     * }
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->authService->register($request->validated());
        $token = $this->authService->generateToken($user);

        // Best effort: a mail failure must not fail registration, since the
        // account and token are already valid. The client can resend.
        $this->verificationService->send($user, $request);

        return $this->successResponse(
            data: [
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames()->values(),
                    'plan' => $user->currentPlanSlug(),
                    'credits_balance' => $user->credits_balance,
                    'email_verified' => $user->hasVerifiedEmail(),
                ],
            ],
            message: 'Account created successfully',
            statusCode: 201
        );
    }

    // POST /api/v1/auth/login
    /**
     * Login
     *
     * Authenticate with email and password. Returns a Bearer token
     * to use in all subsequent protected requests.
     *
     * @unauthenticated
     *
     * @group Authentication
     *
     * @bodyParam email string required Your account email. Example: john@example.com
     * @bodyParam password string required Your password. Example: password123
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Logged in successfully",
     *   "data": {
     *     "token": "2|xxxxxxxxxxxxxxxxxxxxxxxx",
     *     "user": {
     *       "id": 1,
     *       "name": "John Doe",
     *       "email": "john@example.com",
     *       "plan": "free",
     *       "credits_balance": 50
     *     }
     *   }
     * }
     * @response 401 {
     *   "success": false,
     *   "message": "Invalid email or password",
     *   "errors": null
     * }
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->authService->attemptLogin(
            $request->email,
            $request->password
        );

        if (! $user) {
            return $this->errorResponse(
                message: 'Invalid email or password',
                statusCode: 401
            );
        }

        $token = $this->authService->generateToken($user);
        $this->authService->logLogin($user, $request);

        return $this->successResponse(
            data: [
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames()->values(),
                    'plan' => $user->currentPlanSlug(),
                    'credits_balance' => $user->credits_balance,
                ],
            ],
            message: 'Logged in successfully'
        );
    }

    // POST /api/v1/auth/logout

    /**
     * Logout
     *
     * Invalidates the current Bearer token. The token cannot be
     * used again after this call. The user must login again to get a new token.
     *
     * @authenticated
     *
     * @group Authentication
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Logged out successfully",
     *   "data": null
     * }
     * @response 401 {
     *   "message": "Unauthenticated."
     * }
     */
    public function logout(Request $request): JsonResponse
    {
        // Delete only the current token (the one used in this request)
        $request->user()->currentAccessToken()->delete();

        return $this->successResponse(
            message: 'Logged out successfully'
        );
    }
    // GET /api/v1/auth/me
    // Returns the currently authenticated user
    /**
     * Get authenticated user
     *
     * Returns the full profile of the currently authenticated user.
     * Use this after login to populate the dashboard with user data.
     *
     * @authenticated
     *
     * @group Authentication
     *
     * @response 200 {
     *   "success": true,
     *   "message": "User retrieved successfully",
     *   "data": {
     *     "id": 1,
     *     "name": "John Doe",
     *     "email": "john@example.com",
     *     "plan": "free",
     *     "credits_balance": 50,
     *     "profile_image": null,
     *     "email_verified": false,
     *     "is_active": true,
     *     "created_at": "2026-03-24T10:00:00.000000Z"
     *   }
     * }
     * @response 401 {
     *   "message": "Unauthenticated."
     * }
     */

    // public function me(Request $request): JsonResponse
    // {
    //     $user = $request->user();

    //     return $this->successResponse(
    //         data: [
    //             'id'              => $user->id,
    //             'name'            => $user->name,
    //             'email'           => $user->email,
    //             'plan'            => $user->plan,
    //             'credits_balance' => $user->credits_balance,
    //             'profile_image'   => $user->profile_image_url,
    //             'email_verified'  => !is_null($user->email_verified_at),
    //             'is_active'       => $user->is_active,
    //             'created_at'      => $user->created_at,
    //         ],
    //         message: 'User retrieved successfully'
    //     );
    // }

    // POST /api/v1/auth/forgot-password

    /**
     * Request a password reset link
     *
     * Emails a reset link. The expiry is stated in the email itself.
     *
     * Always returns 200 with the same body whether or not the address has an
     * account, so the endpoint cannot be used to discover which emails are
     * registered. `retry_after` is a fixed value, not a per-account one, and
     * tells the client how long the broker will suppress a repeat send for the
     * same address.
     *
     * @unauthenticated
     *
     * @group Authentication
     *
     * @bodyParam email string required The email address on the account. Example: john@example.com
     *
     * @response 200 {
     *   "success": true,
     *   "message": "If that email is registered, a password reset link is on its way.",
     *   "data": {
     *     "retry_after": 60
     *   }
     * }
     * @response 422 {
     *   "success": false,
     *   "message": "Validation failed",
     *   "errors": {
     *     "email": ["The email field is required."]
     *   }
     * }
     * @response 429 {
     *   "message": "Too Many Attempts."
     * }
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->passwordResetService->sendResetLink($request->email, $request);

        // The broker suppresses a second send for the same address inside this
        // window and reports success either way, so the client is told up front
        // rather than being left waiting for an email that will not arrive.
        return $this->successResponse(
            data: [
                'retry_after' => (int) config(
                    'auth.passwords.'.config('auth.defaults.passwords').'.throttle',
                    60
                ),
            ],
            message: 'If that email is registered, a password reset link is on its way.'
        );
    }

    // POST /api/v1/auth/reset-password

    /**
     * Reset password with a token
     *
     * Consumes the token from the reset email and sets a new password. On success
     * every existing session is signed out, so the user must log in again.
     *
     * @unauthenticated
     *
     * @group Authentication
     *
     * @bodyParam token string required The token from the reset link. Example: a1b2c3d4e5f6a1b2c3d4e5f6
     * @bodyParam email string required The email the link was issued for. Example: john@example.com
     * @bodyParam password string required The new password. Min 8 characters. Example: new-password123
     * @bodyParam password_confirmation string required Must match password. Example: new-password123
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Password reset successfully. Please log in with your new password.",
     *   "data": null
     * }
     * @response 400 {
     *   "success": false,
     *   "message": "This password reset link is invalid or has expired.",
     *   "errors": null
     * }
     * @response 422 {
     *   "success": false,
     *   "message": "Validation failed",
     *   "errors": {
     *     "password": ["Password must be at least 8 characters."]
     *   }
     * }
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = $this->passwordResetService->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            $request
        );

        if ($status !== Password::PASSWORD_RESET) {
            // Both an unknown email and a bad or expired token land here, and
            // they share one message so neither can be told apart.
            return $this->errorResponse(
                message: 'This password reset link is invalid or has expired.',
                statusCode: 400
            );
        }

        return $this->successResponse(
            message: 'Password reset successfully. Please log in with your new password.'
        );
    }

    // OAuth
    // GET /api/v1/auth/{provider}/redirect

    /**
     * OAuth Redirect
     *
     * Redirects the user to the Google or GitHub login page.
     * Pass `google` or `github` as the provider parameter.
     * After the user authenticates, they are sent to the callback endpoint.
     *
     * @unauthenticated
     *
     * @group OAuth
     *
     * @urlParam provider string required The OAuth provider. Accepted: google, github. Example: google
     *
     * @response 302 scenario="Redirects to provider login page" {}
     */
    // Redirects user to Google or GitHub login page

    public function oauthRedirect(string $provider)
    {
        $providerEnum = OAuthProviderEnum::tryFrom($provider);

        if (! $providerEnum) {
            return $this->errorResponse(
                message: 'Unsupported OAuth provider',
                statusCode: 400
            );
        }

        return Socialite::driver($providerEnum->value)
            ->stateless()
            ->redirect();
    }
    // GET /api/v1/auth/{provider}/callback
    // Google/GitHub sends the user back here after login

    /**
     * OAuth Callback
     *
     * Handles the response from Google or GitHub after the user authenticates.
     * Returns a Bearer token exactly like the login endpoint does.
     * The frontend should redirect here and extract the token from the response.
     *
     * @unauthenticated
     *
     * @group OAuth
     *
     * @urlParam provider string required The OAuth provider. Accepted: google, github. Example: google
     *
     * @response 200 {
     *   "success": true,
     *   "message": "OAuth login successful",
     *   "data": {
     *     "token": "3|xxxxxxxxxxxxxxxxxxxxxxxx",
     *     "user": {
     *       "id": 2,
     *       "name": "Jane Doe",
     *       "email": "jane@gmail.com",
     *       "plan": "free",
     *       "credits_balance": 50
     *     }
     *   }
     * }
     * @response 400 {
     *   "success": false,
     *   "message": "Unsupported OAuth provider",
     *   "errors": null
     * }
     * @response 500 {
     *   "success": false,
     *   "message": "OAuth authentication failed",
     *   "errors": null
     * }
     */
    public function oauthCallback(Request $request, string $provider): JsonResponse
    {
        try {
            $providerEnum = OAuthProviderEnum::from($provider);
            $socialiteUser = Socialite::driver($provider)->stateless()->user();
            $user = $this->authService->findOrCreateOAuthUser($socialiteUser, $providerEnum);
            $token = $this->authService->generateToken($user);
            $this->authService->logLogin($user, $request);

            return $this->successResponse(
                data: [
                    'token' => $token,
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'roles' => $user->getRoleNames()->values(),
                        'plan' => $user->currentPlanSlug(),
                        'credits_balance' => $user->credits_balance,
                    ],
                ],
                message: 'OAuth login successful'
            );
        } catch (\ValueError $e) {
            return $this->errorResponse(
                message: 'Unsupported OAuth provider',
                statusCode: 400
            );
        } catch (AuthenticationException $e) {
            return $this->errorResponse(
                message: $e->getMessage(),
                statusCode: 403
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                message: 'OAuth authentication failed',
                statusCode: 500
            );
        }
    }
}
