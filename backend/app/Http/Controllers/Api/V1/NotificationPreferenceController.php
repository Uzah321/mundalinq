<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\NotificationCategory;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Which notifications also come by SMS. The in-app inbox always gets everything, and security
 * codes are always texted.
 */
class NotificationPreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->notificationSettings()]);
    }

    /**
     * Body: {"sms": {"messages": false}} and/or {"channel": "whatsapp"}. Categories left out keep their setting.
     */
    public function update(Request $request): JsonResponse
    {
        $categories = array_map(fn (NotificationCategory $category) => $category->value, NotificationCategory::withSms());

        $validated = $request->validate([
            'sms' => ['required_without:channel', 'array:'.implode(',', $categories)],
            'sms.*' => ['boolean'],
            'channel' => ['sometimes', Rule::in(['sms', 'whatsapp'])],
        ]);

        $user = $request->user();
        $sms = array_map('boolval', [...$user->notificationSettings()['sms'], ...($validated['sms'] ?? [])]);

        // Store opt-outs only, so categories added later start switched on.
        $user->forceFill(['notification_preferences' => [
            'sms' => array_filter($sms, fn (bool $on) => ! $on),
            'channel' => $validated['channel'] ?? $user->alertChannel(),
        ]])->save();

        return response()->json(['data' => $user->notificationSettings()]);
    }
}
