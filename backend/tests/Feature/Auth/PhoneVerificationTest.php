<?php

namespace Tests\Feature\Auth;

use App\Enums\OtpPurpose;
use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_verify_phone_with_sms_code(): void
    {
        $user = User::factory()->unverifiedPhone()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/phone/send-code')->assertAccepted();

        $this->postJson('/api/v1/auth/phone/verify', ['code' => $this->sms->lastCodeFor($user->phone)])
            ->assertOk()
            ->assertJsonPath('data.phone_verified', true);
    }

    public function test_wrong_code_is_rejected(): void
    {
        $user = User::factory()->unverifiedPhone()->create();
        Sanctum::actingAs($user);
        app(OtpService::class)->send($user->phone, OtpPurpose::VerifyPhone);
        $wrong = $this->sms->lastCodeFor($user->phone) === '000000' ? '111111' : '000000';

        $this->postJson('/api/v1/auth/phone/verify', ['code' => $wrong])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertNull($user->fresh()->phone_verified_at);
    }

    public function test_resending_invalidates_the_previous_code(): void
    {
        $user = User::factory()->unverifiedPhone()->create();
        $otp = app(OtpService::class);

        $otp->send($user->phone, OtpPurpose::VerifyPhone);
        $first = $this->sms->lastCodeFor($user->phone);
        $otp->send($user->phone, OtpPurpose::VerifyPhone);
        $second = $this->sms->lastCodeFor($user->phone);

        if ($first !== $second) {
            $this->assertFalse($otp->verify($user->phone, OtpPurpose::VerifyPhone, $first));
        }
        $this->assertTrue($otp->verify($user->phone, OtpPurpose::VerifyPhone, $second));
    }

    public function test_code_locks_after_too_many_wrong_attempts(): void
    {
        $user = User::factory()->unverifiedPhone()->create();
        $otp = app(OtpService::class);
        $otp->send($user->phone, OtpPurpose::VerifyPhone);
        $code = $this->sms->lastCodeFor($user->phone);
        $wrong = $code === '000000' ? '111111' : '000000';

        foreach (range(1, config('mundalinq.otp.max_attempts')) as $attempt) {
            $otp->verify($user->phone, OtpPurpose::VerifyPhone, $wrong);
        }

        $this->assertFalse($otp->verify($user->phone, OtpPurpose::VerifyPhone, $code));
    }

    public function test_expired_code_is_rejected(): void
    {
        $user = User::factory()->unverifiedPhone()->create();
        $otp = app(OtpService::class);
        $otp->send($user->phone, OtpPurpose::VerifyPhone);

        $this->travel(config('mundalinq.otp.ttl_minutes') + 1)->minutes();

        $this->assertFalse($otp->verify($user->phone, OtpPurpose::VerifyPhone, $this->sms->lastCodeFor($user->phone)));
    }

    public function test_code_for_one_purpose_cannot_be_used_for_another(): void
    {
        $user = User::factory()->create();
        $otp = app(OtpService::class);
        $otp->send($user->phone, OtpPurpose::VerifyPhone);

        $this->assertFalse($otp->verify($user->phone, OtpPurpose::PasswordReset, $this->sms->lastCodeFor($user->phone)));
    }

    public function test_already_verified_phone_does_not_get_a_new_code(): void
    {
        Sanctum::actingAs($user = User::factory()->create());

        $this->postJson('/api/v1/auth/phone/send-code')->assertConflict();
        $this->assertSame(0, $this->sms->countFor($user->phone));
    }

    public function test_sms_codes_are_rate_limited(): void
    {
        Sanctum::actingAs(User::factory()->unverifiedPhone()->create());

        $this->postJson('/api/v1/auth/phone/send-code')->assertAccepted();
        $this->postJson('/api/v1/auth/phone/send-code')->assertTooManyRequests();
    }
}
