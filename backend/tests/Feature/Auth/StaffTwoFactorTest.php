<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleSlug;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StaffTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(User $user): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['login' => $user->phone, 'password' => 'password', 'device_name' => 'Laptop']);
    }

    public function test_staff_confirm_sign_in_with_a_code_sent_by_sms(): void
    {
        $admin = User::factory()->withRole(RoleSlug::Admin)->create(['phone' => '+263770001234']);

        $challenge = $this->signIn($admin)
            ->assertAccepted()
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonPath('message', 'Enter the code we sent to your phone ending 1234.')
            ->assertJsonMissingPath('token')
            ->json('challenge');
        $code = $this->sms->lastCodeFor($admin->phone);

        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => $code === '000000' ? '111111' : '000000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'The code is invalid or has expired.']);

        $token = $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => $code])
            ->assertOk()
            ->assertJsonPath('user.id', $admin->id)
            ->json('token');

        $this->getJson('/api/v1/admin/dashboard', ['Authorization' => 'Bearer '.$token])->assertOk();
        $this->assertSame('Laptop', $admin->tokens()->value('name'));

        // The code works once, so the challenge cannot be replayed.
        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => $code])->assertUnprocessable();
    }

    public function test_challenges_expire_and_cannot_be_forged(): void
    {
        $agent = User::factory()->withRole(RoleSlug::SupportAgent)->create();
        $challenge = $this->signIn($agent)->json('challenge');
        $code = $this->sms->lastCodeFor($agent->phone);

        $this->postJson('/api/v1/auth/two-factor', ['challenge' => 'forged', 'code' => $code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['challenge' => 'This sign-in has expired. Please sign in again.']);

        $this->travel(11)->minutes();
        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => $code])
            ->assertJsonValidationErrors(['challenge']);
    }

    public function test_buyers_and_farmers_sign_in_in_one_step(): void
    {
        $this->signIn(User::factory()->withRole(RoleSlug::Farmer)->create())
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);
    }
}
