<?php

namespace App\Http\Requests\Disputes;

use App\Enums\DisputeReason;
use App\Rules\DecimalString;
use App\Support\Money;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class OpenDisputeRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('dispute', $this->route('sellerOrder'));
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::enum(DisputeReason::class)],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            // What the buyer would like back; support decides the actual amount.
            'requested_refund' => ['nullable', DecimalString::money()],
        ];
    }

    public function requestedRefundMinor(): ?int
    {
        return $this->filled('requested_refund') ? Money::toMinor($this->validated('requested_refund')) : null;
    }
}
