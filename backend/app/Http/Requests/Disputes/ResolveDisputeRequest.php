<?php

namespace App\Http\Requests\Disputes;

use App\Enums\DisputeResolution;
use App\Rules\DecimalString;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveDisputeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'resolution' => ['required', Rule::enum(DisputeResolution::class)],
            'refund_amount' => ['required_if:resolution,refund_partial', 'prohibited_unless:resolution,refund_partial', 'nullable', DecimalString::money()],
            // Sent to the buyer and the farm.
            'note' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    public function resolution(): DisputeResolution
    {
        return DisputeResolution::from($this->validated('resolution'));
    }

    public function partialRefundMinor(): ?int
    {
        return $this->filled('refund_amount') ? Money::toMinor($this->validated('refund_amount')) : null;
    }
}
