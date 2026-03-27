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
    'plan',
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
        return in_array($this->plan, ['basic', 'pro', 'enterprise']);
    }
}
