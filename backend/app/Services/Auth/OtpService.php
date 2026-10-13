<?php

namespace App\Services\Auth;

use App\Contracts\SmsGateway;
use App\Enums\OtpPurpose;
use App\Models\OtpCode;
use Illuminate\Support\Facades\DB;

class OtpService
{
    public function __construct(private SmsGateway $sms) {}

    public function send(string $phone, OtpPurpose $purpose): void
    {
        // Only the newest code is valid; retire any earlier ones.
        OtpCode::query()->active($phone, $purpose)->update(['consumed_at' => now()]);

        $code = $this->generateCode();
        $ttl = config('mundalinq.otp.ttl_minutes');

        OtpCode::create([
            'identifier' => $phone,
            'purpose' => $purpose,
            'code_hash' => $this->hash($code),
            'expires_at' => now()->addMinutes($ttl),
        ]);

        $this->sms->send($phone, __('Your :app code is :code. It expires in :minutes minutes. Do not share it.', [
            'app' => config('app.name'),
            'code' => $code,
            'minutes' => $ttl,
        ]));
    }

    /**
     * Check a code and consume it on success. Each wrong guess counts towards the attempt limit.
     */
    public function verify(string $phone, OtpPurpose $purpose, string $code): bool
    {
        return DB::transaction(function () use ($phone, $purpose, $code) {
            $otp = OtpCode::query()->active($phone, $purpose)->latest()->lockForUpdate()->first();

            if (! $otp || $otp->attempts >= config('mundalinq.otp.max_attempts')) {
                return false;
            }

            if (! hash_equals($otp->code_hash, $this->hash($code))) {
                $otp->increment('attempts');

                return false;
            }

            $otp->update(['consumed_at' => now()]);

            return true;
        });
    }

    private function generateCode(): string
    {
        $length = config('mundalinq.otp.length');

        return str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, config('app.key'));
    }
}
