<?php

namespace App\Http\Requests\Addresses;

use App\Http\Requests\Farms\LocationRules;
use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create (POST) or partially update (PATCH) a delivery address.
 */
class SaveAddressRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge(['phone' => Phone::normalize($this->input('phone'))]);
        }
    }

    public function rules(): array
    {
        $partial = $this->isMethod('PATCH');
        $required = $partial ? ['sometimes', 'required'] : ['required'];
        $location = LocationRules::for(partial: $partial);
        unset($location['name']);

        return [
            ...$location,
            'label' => [...$required, 'string', 'max:50'],
            'recipient_name' => [...$required, 'string', 'max:120'],
            'phone' => [...$required, 'string', 'regex:'.Phone::PATTERN],
            'address_line' => [...$required, 'string', 'max:255'],
            'directions' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
