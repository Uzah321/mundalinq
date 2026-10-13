<?php

namespace App\Http\Requests\Farms;

use App\Enums\FarmerType;
use App\Rules\DecimalString;
use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreFarmRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('contact_phone')) {
            $this->merge(['contact_phone' => Phone::normalize($this->input('contact_phone'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'farmer_type' => ['required', Rule::enum(FarmerType::class)],
            'contact_phone' => ['nullable', 'string', 'regex:'.Phone::PATTERN],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'offers_pickup' => ['sometimes', 'boolean'],
            'offers_delivery' => ['sometimes', 'boolean'],
            'delivery_radius_km' => ['nullable', 'required_if_accepted:offers_delivery', 'numeric', 'between:0.5,500'],
            'delivery_fee' => ['nullable', DecimalString::money()->allowingZero()],
            'free_delivery_over' => ['nullable', DecimalString::money()],
            'location' => ['required', 'array'],
            ...LocationRules::for('location.'),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $this->boolean('offers_pickup', true) && ! $this->boolean('offers_delivery')) {
                    $validator->errors()->add('offers_pickup', __('Offer at least pickup or delivery.'));
                }
            },
        ];
    }

    public function farmAttributes(): array
    {
        return [
            ...collect($this->validated())->except(['location', 'delivery_fee', 'free_delivery_over'])->all(),
            ...DeliveryFees::attributes($this->validated()),
        ];
    }

    public function locationAttributes(): array
    {
        return [
            ...$this->validated('location'),
            'name' => $this->validated('location.name') ?? __('Main farm'),
        ];
    }
}
