<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'name',
    'email',
    'password',
    'profile_image_url',
    'profile_image_public_id',

    'credits_balance',
    'credits_monthly_quota',
    'is_active',
    'email_verified_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected string $guard_name = 'api';

    protected static function booted(): void
    {
        static::created(function (User $user): void {
            $user->assignRole('user');
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    // Relationships

    public function oauthProviders()
    {
        return $this->hasMany(OAuthProvider::class);
    }

    public function notificationPreferences()
    {
        return $this->hasOne(NotificationPreference::class);
    }

    public function activities()
    {
        return $this->hasMany(UserActivity::class)->latest('created_at');
    }

    public function creditTransactions()
    {
        return $this->hasMany(CreditTransaction::class)->latest('created_at');
    }

    public function storeScanRequests()
    {
        return $this->hasMany(StoreScanRequest::class);
    }

    public function inAppNotifications()
    {
        return $this->hasMany(InAppNotification::class)->latest('created_at');
    }

    /**
     * Send the email verification link.
     *
     * Overrides the MustVerifyEmail trait, whose default notification links to a
     * `verification.verify` web route this API does not serve.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    /**
     * Send the password reset email.
     *
     * Overrides the CanResetPassword trait, whose default notification links to
     * a `password.reset` web route this API does not define.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
    //  Helper Methods

    // Check if user has enough credits for an action

    public function hasCredits(int $amount): bool
    {
        return $this->hasUnlimitedCredits() || $this->credits_balance >= $amount;
    }

    public function hasUnlimitedCredits(): bool
    {
        return $this->credits_monthly_quota === -1;
    }

    // Check if user is on a paid plan

    public function isPaidUser(): bool
    {
        $sub = $this->activeSubscription;

        return $sub && ! $sub->plan->isFree();
    }

    // Add this relationship
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    // Add this relationship — gets only the current active subscription
    public function activeSubscription()
    {
        return $this->hasOne(Subscription::class)
            ->whereIn('status', ['active', 'trial', 'cancelled'])
            ->where('current_period_end', '>', now())
            ->latest();
    }

    // Get the user's current plan name
    public function currentPlanSlug(): string
    {
        return $this->activeSubscription?->plan?->slug ?? 'free';
    }

    // Get the user's current monthly quota
    public function currentMonthlyQuota(): int
    {
        return $this->activeSubscription?->plan?->monthly_quota ?? 50;
    }
}
