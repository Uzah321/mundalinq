<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfileAndSessionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_profile_and_changing_email_resets_verification(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('Phone')->plainTextToken;

        $this->patchJson('/api/v1/me', ['name' => 'New Name', 'email' => 'NEW@example.com', 'locale' => 'sn'], [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.email', 'new@example.com')
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonPath('data.locale', 'sn');
    }

    public function test_phone_and_roles_cannot_be_changed_through_profile_update(): void
    {
        $user = User::factory()->create(['phone' => '+263771234567']);
        $token = $user->createToken('Phone')->plainTextToken;

        $this->patchJson('/api/v1/me', ['phone' => '+263770000000', 'status' => 'active', 'roles' => ['admin']], [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();

        $this->assertSame('+263771234567', $user->fresh()->phone);
        $this->assertCount(0, $user->fresh()->roles);
    }

    public function test_user_can_list_and_revoke_devices(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('Phone')->plainTextToken;
        $user->createToken('Tablet');
        $headers = ['Authorization' => 'Bearer '.$current];

        $sessions = $this->getJson('/api/v1/auth/sessions', $headers)->assertOk()->json('data');
        $this->assertCount(2, $sessions);

        $tablet = collect($sessions)->firstWhere('device_name', 'Tablet');
        $this->assertFalse($tablet['current']);

        $this->deleteJson('/api/v1/auth/sessions/'.$tablet['id'], [], $headers)->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_user_cannot_revoke_another_users_device(): void
    {
        $other = User::factory()->create();
        $otherToken = $other->createToken('Their phone')->accessToken;
        $token = User::factory()->create()->createToken('Phone')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->deleteJson('/api/v1/auth/sessions/'.$otherToken->id, [], $headers)->assertNotFound();
        $this->deleteJson('/api/v1/auth/sessions/not-a-uuid', [], $headers)->assertNotFound();
        $this->deleteJson('/api/v1/auth/sessions/'.Str::uuid(), [], $headers)->assertNotFound();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->id]);
    }
}
