<?php

namespace App\Services;

use App\Enums\OAuthProviderEnum;
use App\Models\OAuthProvider;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class AuthService
{
    /**
     * Register a new user with email and password.
     */
    public function register(array $data): User
    {
        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => $data['password'], // Auto-hashed by model cast
        ]);

        // Assign the default 'user' role
        $user->assignRole('user');

        return $user;
    }

    /**
     * Attempt to log in with email and password.
     * Returns the user if credentials are valid, null if not.
     */
    public function attemptLogin(string $email, string $password): ?User
    {
        $user = User::where('email', $email)->first();

        if (!$user || !Hash::check($password, $user->password)) {
            return null;
        }

        if (!$user->is_active) {
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

        // First check if we already have this OAuth link
        $oauthRecord = OAuthProvider::where('provider', $provider->value)
            ->where('provider_id', $socialiteUser->getId())
            ->first();

        if ($oauthRecord) {
            // Update the access token and return the linked user
            $oauthRecord->update(['access_token' => $socialiteUser->token]);
            return $oauthRecord->user;
        }

        // No OAuth record found — check if email already exists
        // (User may have registered with email first, now linking Google)
        $user = User::where('email', $socialiteUser->getEmail())->first();

        if (!$user) {
            // Brand new user — create their account
            $user = User::create([
                'name'              => $socialiteUser->getName(),
                'email'             => $socialiteUser->getEmail(),
                'password'          => null, // OAuth users have no password
                'profile_image_url' => $socialiteUser->getAvatar(),
                'email_verified_at' => now(), // OAuth emails are pre-verified
            ]);

            $user->assignRole('user');
        }

        // Create the OAuth link between user and provider
        OAuthProvider::create([
            'user_id'      => $user->id,
            'provider'     => $provider->value,
            'provider_id'  => $socialiteUser->getId(),
            'access_token' => $socialiteUser->token,
        ]);

        return $user;
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
}
