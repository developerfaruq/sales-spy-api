<?php

namespace Tests\Feature\Auth;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_assigns_the_default_user_role(): void
    {
        Plan::create([
            'slug' => 'free',
            'name' => 'Free',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'monthly_quota' => 50,
            'features' => [],
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Role Test User',
            'email' => 'role-test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.user.roles.0', 'user');

        $user = User::findOrFail($response->json('data.user.id'));

        $this->assertTrue($user->hasRole('user'));
    }
}
