<?php

namespace App\Http\Resources;

use App\Models\FarmPayoutAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full number is never sent back: only its last four digits.
 *
 * @mixin FarmPayoutAccount
 */
class PayoutAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'method' => $this->method,
            'provider' => $this->provider,
            'account_name' => $this->account_name,
            'account_number_last4' => $this->lastFour(),
            // Payouts are paused until then after a change.
            'on_hold_until' => $this->isOnHold()
                ? $this->updated_at->copy()->addHours(config('mundalinq.payouts.account_change_hold_hours'))->toIso8601String()
                : null,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
