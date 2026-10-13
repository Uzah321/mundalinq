<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\OtpPurpose;
use App\Enums\RoleSlug;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use JsonException;

class AuthController extends Controller
{
    /**
     * Create an account from the mobile app and sign the device in.
     * The phone number still has to be verified with the SMS code sent here.
     */
    public function register(RegisterRequest $request, OtpService $otp): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create($request->safe()->only(['name', 'phone', 'email', 'password']));
            $user->assignRole(RoleSlug::from($request->validated('role')));

            return $user;
        });

        $otp->send($user->phone, OtpPurpose::VerifyPhone);

        return $this->tokenResponse($user, $request->validated('device_name'), Response::HTTP_CREATED);
    }

    /**
     * Staff accounts get no token yet: 202 with a challenge, and a code by SMS for POST /auth/two-factor.
     */
    public function login(LoginRequest $request, OtpService $otp): JsonResponse
    {
        $user = User::where($request->loginColumn(), $request->validated('login'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages(['login' => __('auth.failed')]);
        }

        abort_if($user->isSuspended(), Response::HTTP_FORBIDDEN, __('This account has been suspended. Please contact support.'));

        if ($user->isStaff() && config('mundalinq.auth.staff_two_factor')) {
            return $this->twoFactorChallenge($user, $request->validated('device_name'), $otp);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $this->tokenResponse($user, $request->validated('device_name'));
    }

    /**
     * Second step of a staff sign-in. Body: {"challenge": "...", "code": "123456"}.
     */
    public function confirmTwoFactor(Request $request, OtpService $otp): JsonResponse
    {
        $validated = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $challenge = $this->readChallenge($validated['challenge']);
        $user = $challenge ? User::find($challenge['user_id']) : null;

        if ($user === null) {
            throw ValidationException::withMessages(['challenge' => __('This sign-in has expired. Please sign in again.')]);
        }

        abort_if($user->isSuspended(), Response::HTTP_FORBIDDEN, __('This account has been suspended. Please contact support.'));

        if (! $otp->verify($user->phone, OtpPurpose::StaffSignIn, $validated['code'])) {
            throw ValidationException::withMessages(['code' => __('The code is invalid or has expired.')]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $this->tokenResponse($user, $challenge['device_name']);
    }

    /**
     * Sign out the current device only.
     */
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    /**
     * The challenge is encrypted and short-lived, so no server state is kept between the two steps.
     * It cannot be replayed: the SMS code it pairs with works once.
     */
    private function twoFactorChallenge(User $user, string $deviceName, OtpService $otp): JsonResponse
    {
        abort_unless($user->phone, Response::HTTP_FORBIDDEN, __('Staff accounts need a phone number to sign in. Ask a super administrator to add one.'));

        $otp->send($user->phone, OtpPurpose::StaffSignIn);

        return response()->json([
            'two_factor_required' => true,
            'challenge' => Crypt::encryptString(json_encode([
                'user_id' => $user->id,
                'device_name' => $deviceName,
                'expires_at' => now()->addMinutes(config('mundalinq.auth.two_factor_challenge_minutes'))->getTimestamp(),
            ])),
            'message' => __('Enter the code we sent to your phone ending :last4.', ['last4' => substr($user->phone, -4)]),
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * @return array{user_id: string, device_name: string, expires_at: int}|null
     */
    private function readChallenge(string $challenge): ?array
    {
        try {
            $data = json_decode(Crypt::decryptString($challenge), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        return is_array($data) && ($data['expires_at'] ?? 0) > now()->getTimestamp() ? $data : null;
    }

    private function tokenResponse(User $user, string $deviceName, int $status = Response::HTTP_OK): JsonResponse
    {
        $token = $user->createToken($deviceName, ['*'], now()->addDays(config('mundalinq.auth.token_ttl_days')));

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => new UserResource($user->load('roles')),
        ], $status);
    }
}
