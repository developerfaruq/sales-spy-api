<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use App\Models\OAuthProvider;
use App\Models\NotificationPreference;
use App\Models\UserActivity;

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
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasRoles;
    protected string $guard_name = 'api';
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
    //  Helper Methods 


    //Check if user has enough credits for an action

    public function hasCredits(int $amount): bool
    {
        return $this->credits_balance >= $amount;
    }



    //Check if user is on a paid plan

    public function isPaidUser(): bool
    {
        $sub = $this->activeSubscription;
        return $sub && !$sub->plan->isFree();
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
