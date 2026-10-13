<?php

namespace App\Http\Requests\Farms;

use App\Enums\FarmerType;
use App\Enums\FarmVerificationStatus;
use App\Models\Farm;
use App\Rules\DecimalString;
use App\Support\Phone;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateFarmRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('farm'));
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('contact_phone')) {
            $this->merge(['contact_phone' => Phone::normalize($this->input('contact_phone'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'farmer_type' => ['sometimes', 'required', Rule::enum(FarmerType::class)],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'regex:'.Phone::PATTERN],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'offers_pickup' => ['sometimes', 'boolean'],
            'offers_delivery' => ['sometimes', 'boolean'],
            'delivery_radius_km' => ['sometimes', 'nullable', 'numeric', 'between:0.5,500'],
            'delivery_fee' => ['sometimes', 'nullable', DecimalString::money()->allowingZero()],
            'free_delivery_over' => ['sometimes', 'nullable', DecimalString::money()],
        ];
    }

    public function farmAttributes(): array
    {
        return [
            ...collect($this->validated())->except(['delivery_fee', 'free_delivery_over'])->all(),
            ...DeliveryFees::attributes($this->validated()),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var Farm $farm */
                $farm = $this->route('farm');

                // The farmer type decides which documents are required, so it is fixed once verification starts.
                if ($this->has('farmer_type')
                    && $this->input('farmer_type') !== $farm->farmer_type->value
                    && $farm->verification_status !== FarmVerificationStatus::Unverified
                    && $farm->verification_status !== FarmVerificationStatus::Rejected) {
                    $validator->errors()->add('farmer_type', __('The farmer type cannot be changed after verification has been submitted.'));
                }

                $pickup = $this->has('offers_pickup') ? $this->boolean('offers_pickup') : $farm->offers_pickup;
                $delivery = $this->has('offers_delivery') ? $this->boolean('offers_delivery') : $farm->offers_delivery;
                $radius = $this->has('delivery_radius_km') ? $this->input('delivery_radius_km') : $farm->delivery_radius_km;

                if (! $pickup && ! $delivery) {
                    $validator->errors()->add('offers_pickup', __('Offer at least pickup or delivery.'));
                }

                if ($delivery && $radius === null) {
                    $validator->errors()->add('delivery_radius_km', __('Set a delivery radius when offering delivery.'));
                }
            },
        ];
    }
}
