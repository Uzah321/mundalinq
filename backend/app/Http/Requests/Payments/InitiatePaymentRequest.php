<?php

namespace App\Http\Requests\Payments;

use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Support\Phone;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Body: {"order_id": "...", "method": "mobile_money", "phone": "0771234567"}.
 */
class InitiatePaymentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge(['phone' => Phone::normalize($this->input('phone'))]);
        }
    }

    public function authorize(): Response
    {
        $order = $this->order();

        // An unknown order is reported by validation instead.
        return $order ? Gate::inspect('act', $order) : Response::allow();
    }

    public function rules(): array
    {
        return [
            'order_id' => ['bail', 'required', 'uuid', Rule::exists('orders', 'id')],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'phone' => ['required', 'string', 'regex:'.Phone::PATTERN],
        ];
    }

    public function order(): ?Order
    {
        $id = $this->input('order_id');

        return is_string($id) && Str::isUuid($id) ? Order::find($id) : null;
    }
}
