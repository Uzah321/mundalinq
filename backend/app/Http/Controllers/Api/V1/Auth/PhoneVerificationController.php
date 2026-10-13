<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyPhoneRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class PhoneVerificationController extends Controller
{
    /**
     * Send (or resend) the SMS verification code to the signed-in user's phone.
     */
    public function send(Request $request, OtpService $otp): JsonResponse
    {
        $user = $request->user();

        abort_if($user->hasVerifiedPhone(), Response::HTTP_CONFLICT, __('Your phone number is already verified.'));

        $otp->send($user->phone, OtpPurpose::VerifyPhone);

        return response()->json(['message' => __('A verification code has been sent to your phone.')], Response::HTTP_ACCEPTED);
    }

    public function verify(VerifyPhoneRequest $request, OtpService $otp): UserResource
    {
        $user = $request->user();

        if (! $user->hasVerifiedPhone()) {
            if (! $otp->verify($user->phone, OtpPurpose::VerifyPhone, $request->validated('code'))) {
                throw ValidationException::withMessages(['code' => __('The code is invalid or has expired.')]);
            }

            $user->markPhoneAsVerified();
        }

        return new UserResource($user->load('roles'));
    }
}
