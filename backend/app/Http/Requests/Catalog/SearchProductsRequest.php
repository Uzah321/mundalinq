<?php

namespace App\Http\Requests\Catalog;

use App\Enums\QualityGrade;
use App\Rules\DecimalString;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SearchProductsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', Rule::exists('categories', 'slug')->where('is_active', true)],
            'farm_id' => ['nullable', 'uuid'],
            'province' => ['nullable', 'string', Rule::in(config('mundalinq.countries.ZW.provinces'))],
            'district' => ['nullable', 'string', 'max:80'],
            'grade' => ['nullable', Rule::enum(QualityGrade::class)],
            'min_price' => ['nullable', DecimalString::money()],
            'max_price' => ['nullable', DecimalString::money()],
            'fulfilment' => ['nullable', Rule::in(['pickup', 'delivery'])],
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'numeric', 'between:1,500'],
            'sort' => ['nullable', Rule::in(['newest', 'nearest', 'price_asc', 'price_desc', 'freshest'])],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->input('sort') === 'nearest' && ! $this->filled('lat')) {
                    $validator->errors()->add('sort', __('Share your location to sort by distance.'));
                }

                if ($this->filled(['min_price', 'max_price'])
                    && $validator->errors()->isEmpty()
                    && (float) $this->input('min_price') > (float) $this->input('max_price')) {
                    $validator->errors()->add('max_price', __('The maximum price must be at least the minimum price.'));
                }
            },
        ];
    }
}
