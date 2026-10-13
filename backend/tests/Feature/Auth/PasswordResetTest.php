<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_reset_password_with_sms_code(): void
    {
        $user = User::factory()->create(['phone' => '+263771234567']);
        $user->createToken('Old phone');

        $this->postJson('/api/v1/auth/password/forgot', ['phone' => '0771234567'])->assertAccepted();

        $this->postJson('/api/v1/auth/password/reset', [
            'phone' => '0771234567',
            'code' => $this->sms->lastCodeFor('+263771234567'),
            'password' => 'new-secret-pass',
            'password_confirmation' => 'new-secret-pass',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-secret-pass', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unknown_number_gets_the_same_response_but_no_sms(): void
    {
        $this->postJson('/api/v1/auth/password/forgot', ['phone' => '+263779999999'])
            ->assertAccepted()
            ->assertJsonPath('message', 'If an account exists for this number, a reset code has been sent.');

        $this->assertSame(0, $this->sms->countFor('+263779999999'));
    }

    public function test_reset_fails_with_wrong_code(): void
    {
        $user = User::factory()->create();
        $this->postJson('/api/v1/auth/password/forgot', ['phone' => $user->phone])->assertAccepted();
        $wrong = $this->sms->lastCodeFor($user->phone) === '000000' ? '111111' : '000000';

        $this->postJson('/api/v1/auth/password/reset', [
            'phone' => $user->phone,
            'code' => $wrong,
            'password' => 'new-secret-pass',
            'password_confirmation' => 'new-secret-pass',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }
}
