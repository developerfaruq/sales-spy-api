<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Enums\OAuthProviderEnum;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function __construct(
        protected AuthService $authService
    ) {}

    //Email & Password Auth

    //POST /api/v1/auth/register

    /**
     * Register a new user
     *
     * Creates a new user account and returns an auth token immediately.
     * The user is assigned the free plan with 50 starter credits.
     *
     * @unauthenticated
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
     *
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
        $user  = $this->authService->register($request->validated());
        $token = $this->authService->generateToken($user);

        return $this->successResponse(
            data: [
                'token' => $token,
                'user'  => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'plan'  => $user->plan,
                    'credits_balance' => $user->credits_balance,
                ],
            ],
            message: 'Account created successfully',
            statusCode: 201
        );
    }

    //POST /api/v1/auth/login
    /**
     * Login
     *
     * Authenticate with email and password. Returns a Bearer token
     * to use in all subsequent protected requests.
     *
     * @unauthenticated
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
     *
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

        if (!$user) {
            return $this->errorResponse(
                message: 'Invalid email or password',
                statusCode: 401
            );
        }

        $token = $this->authService->generateToken($user);

        return $this->successResponse(
            data: [
                'token' => $token,
                'user'  => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'plan'  => $user->plan,
                    'credits_balance' => $user->credits_balance,
                ],
            ],
            message: 'Logged in successfully'
        );
    }

    //POST /api/v1/auth/logout

    /**
     * Logout
     *
     * Invalidates the current Bearer token. The token cannot be
     * used again after this call. The user must login again to get a new token.
     *
     * @authenticated
     * @group Authentication
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Logged out successfully",
     *   "data": null
     * }
     *
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
    //GET /api/v1/auth/me
    //Returns the currently authenticated user
    /**
     * Get authenticated user
     *
     * Returns the full profile of the currently authenticated user.
     * Use this after login to populate the dashboard with user data.
     *
     * @authenticated
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
     *
     * @response 401 {
     *   "message": "Unauthenticated."
     * }
     */

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->successResponse(
            data: [
                'id'              => $user->id,
                'name'            => $user->name,
                'email'           => $user->email,
                'plan'            => $user->plan,
                'credits_balance' => $user->credits_balance,
                'profile_image'   => $user->profile_image_url,
                'email_verified'  => !is_null($user->email_verified_at),
                'is_active'       => $user->is_active,
                'created_at'      => $user->created_at,
            ],
            message: 'User retrieved successfully'
        );
    }

    // OAuth
    //GET /api/v1/auth/{provider}/redirect

    /**
     * OAuth Redirect
     *
     * Redirects the user to the Google or GitHub login page.
     * Pass `google` or `github` as the provider parameter.
     * After the user authenticates, they are sent to the callback endpoint.
     *
     * @unauthenticated
     * @group OAuth
     *
     * @urlParam provider string required The OAuth provider. Accepted: google, github. Example: google
     *
     * @response 302 scenario="Redirects to provider login page" {}
     */
    //Redirects user to Google or GitHub login page

    public function oauthRedirect(string $provider)
    {
        return Socialite::driver($provider)
            ->stateless()
            ->redirect();
    }
    //GET /api/v1/auth/{provider}/callback
    //Google/GitHub sends the user back here after login

    /**
     * OAuth Callback
     *
     * Handles the response from Google or GitHub after the user authenticates.
     * Returns a Bearer token exactly like the login endpoint does.
     * The frontend should redirect here and extract the token from the response.
     *
     * @unauthenticated
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
     *
     * @response 400 {
     *   "success": false,
     *   "message": "Unsupported OAuth provider",
     *   "errors": null
     * }
     *
     * @response 500 {
     *   "success": false,
     *   "message": "OAuth authentication failed",
     *   "errors": null
     * }
     */
    public function oauthCallback(string $provider): JsonResponse
    {
        try {
            $providerEnum     = OAuthProviderEnum::from($provider);
            $socialiteUser    = Socialite::driver($provider)->stateless()->user();
            $user             = $this->authService->findOrCreateOAuthUser($socialiteUser, $providerEnum);
            $token            = $this->authService->generateToken($user);

            return $this->successResponse(
                data: [
                    'token' => $token,
                    'user'  => [
                        'id'    => $user->id,
                        'name'  => $user->name,
                        'email' => $user->email,
                        'plan'  => $user->plan,
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
        } catch (\Exception $e) {
            return $this->errorResponse(
                message: 'OAuth authentication failed',
                statusCode: 500
            );
        }
    }
}
