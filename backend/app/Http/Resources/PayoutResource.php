<?php

namespace App\Http\Resources;

use App\Models\Payout;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payout
 */
class PayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'farm' => $this->whenLoaded('farm', fn () => ['id' => $this->farm->id, 'name' => $this->farm->name]),
            'amount' => Money::format($this->amount_minor),
            'currency' => $this->currency,
            'method' => $this->method,
            'provider' => $this->provider,
            'account_name' => $this->account_name,
            'account_number_last4' => $this->account_number_last4,
            'reference' => $this->reference,
            'note' => $this->note,
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }
}
