<?php

namespace App\Services;

use App\Enums\OAuthProviderEnum;
use App\Models\OAuthProvider;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class AuthService
{
    public function __construct(
        protected ActivityService $activityService,
        protected SubscriptionService $subscriptionService,
    ) {}

    /**
     * Register a new user with email and password.
     */
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $this->subscriptionService->assignFreePlan($user);

            return $user->refresh();
        });
    }

    /**
     * Attempt to log in with email and password.
     * Returns the user if credentials are valid, null if not.
     */
    public function attemptLogin(string $email, string $password): ?User
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! $user->password || ! Hash::check($password, $user->password)) {
            return null;
        }

        if (! $user->is_active) {
            return null;
        }

        return $user;
    }

    /**
     * Find or create a user from an OAuth provider.
     * This handles both:
     * - First time login with Google/GitHub (creates new user)
     * - Returning user login with Google/GitHub (finds existing user)
     */
    public function findOrCreateOAuthUser(
        SocialiteUser $socialiteUser,
        OAuthProviderEnum $provider
    ): User {
        return DB::transaction(function () use ($socialiteUser, $provider): User {
            $oauthRecord = OAuthProvider::where('provider', $provider->value)
                ->where('provider_id', $socialiteUser->getId())
                ->first();

            if ($oauthRecord) {
                $user = $oauthRecord->user;

                $this->ensureActive($user);
                $this->ensureSubscription($user);
                $oauthRecord->update(['access_token' => $socialiteUser->token]);

                return $user->refresh();
            }

            $email = $socialiteUser->getEmail();

            if (! $email) {
                throw new AuthenticationException('The OAuth provider did not return an email address.');
            }

            $user = User::where('email', $email)->first();

            if (! $user) {
                $user = User::create([
                    'name' => $socialiteUser->getName() ?: $email,
                    'email' => $email,
                    'password' => null,
                    'profile_image_url' => $socialiteUser->getAvatar(),
                    'email_verified_at' => now(),
                ]);

                $this->subscriptionService->assignFreePlan($user);
            } else {
                $this->ensureActive($user);
                $this->ensureSubscription($user);
            }

            OAuthProvider::create([
                'user_id' => $user->id,
                'provider' => $provider->value,
                'provider_id' => $socialiteUser->getId(),
                'access_token' => $socialiteUser->token,
            ]);

            return $user->refresh();
        });
    }

    /**
     * Generate a Sanctum token for a user.
     * This is the token the frontend stores and sends
     * with every subsequent request.
     */
    public function generateToken(User $user): string
    {
        // Delete old tokens to prevent accumulation
        // Each login creates a fresh token
        $user->tokens()->delete();

        return $user->createToken('auth-token')->plainTextToken;
    }

    /**
     * Log a login activity for the user.
     */
    public function logLogin(User $user, Request $request): void
    {
        $this->activityService->log(
            userId: $user->id,
            type: 'login',
            description: 'Signed in to account',
            request: $request
        );
    }

    private function ensureActive(User $user): void
    {
        if (! $user->is_active) {
            throw new AuthenticationException('This account has been deactivated.');
        }
    }

    private function ensureSubscription(User $user): void
    {
        if (! $user->activeSubscription()->exists()) {
            $this->subscriptionService->assignFreePlan($user);
        }
    }
}
