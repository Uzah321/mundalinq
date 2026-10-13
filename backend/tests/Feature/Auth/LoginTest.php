<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleSlug;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_log_in_with_local_phone_format(): void
    {
        User::factory()->withRole(RoleSlug::Buyer)->create(['phone' => '+263771234567']);

        $this->postJson('/api/v1/auth/login', [
            'login' => '0771234567',
            'password' => 'password',
            'device_name' => 'iPhone 15',
        ])->assertOk()
            ->assertJsonStructure(['token', 'user'])
            ->assertJsonPath('user.roles', ['buyer']);
    }

    public function test_user_can_log_in_with_email(): void
    {
        User::factory()->create(['email' => 'rudo@example.com']);

        $this->postJson('/api/v1/auth/login', [
            'login' => 'RUDO@example.com',
            'password' => 'password',
            'device_name' => 'Galaxy A15',
        ])->assertOk();
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/login', [
            'login' => $user->phone,
            'password' => 'wrong',
            'device_name' => 'Pixel',
        ])->assertUnprocessable()->assertJsonValidationErrors('login');
    }

    public function test_suspended_user_cannot_log_in_or_use_existing_tokens(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('Pixel')->plainTextToken;
        $user->forceFill(['status' => 'suspended'])->save();

        $this->postJson('/api/v1/auth/login', [
            'login' => $user->phone,
            'password' => 'password',
            'device_name' => 'Pixel',
        ])->assertForbidden();

        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$token])->assertForbidden();
    }

    public function test_protected_routes_require_a_token(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer not-a-uuid|garbage'])->assertUnauthorized();
    }

    public function test_logout_revokes_only_the_current_device(): void
    {
        $user = User::factory()->create();
        $phoneToken = $user->createToken('Phone')->plainTextToken;
        $tabletToken = $user->createToken('Tablet')->plainTextToken;

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer '.$phoneToken])->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$tabletToken])->assertOk();
    }
}
