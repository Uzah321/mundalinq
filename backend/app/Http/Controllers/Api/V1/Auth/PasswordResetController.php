<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    /**
     * Send a reset code by SMS. The response is the same whether or not the
     * number is registered, so it cannot be used to discover accounts.
     */
    public function forgot(ForgotPasswordRequest $request, OtpService $otp): JsonResponse
    {
        $phone = $request->validated('phone');

        if (User::where('phone', $phone)->exists()) {
            $otp->send($phone, OtpPurpose::PasswordReset);
        }

        return response()->json([
            'message' => __('If an account exists for this number, a reset code has been sent.'),
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * Set a new password and sign out every device.
     */
    public function reset(ResetPasswordRequest $request, OtpService $otp): JsonResponse
    {
        $user = User::where('phone', $request->validated('phone'))->first();

        if (! $user || ! $otp->verify($user->phone, OtpPurpose::PasswordReset, $request->validated('code'))) {
            throw ValidationException::withMessages(['code' => __('The code is invalid or has expired.')]);
        }

        DB::transaction(function () use ($user, $request) {
            $user->forceFill([
                'password' => $request->validated('password'),
                // Receiving the SMS code proves the user controls this number.
                'phone_verified_at' => $user->phone_verified_at ?? now(),
            ])->save();

            $user->tokens()->delete();
        });

        return response()->json(['message' => __('Your password has been reset. Please log in.')]);
    }
}
