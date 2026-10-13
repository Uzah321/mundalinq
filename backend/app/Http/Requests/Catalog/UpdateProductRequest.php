<?php

namespace App\Http\Requests\Catalog;

use App\Enums\QualityGrade;
use App\Models\Product;
use App\Rules\DecimalString;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('manage', $this->product());
    }

    public function rules(): array
    {
        return [
            'category_id' => ['bail', 'sometimes', 'required', 'uuid', Rule::exists('categories', 'id')->where('is_active', true)],
            'farm_location_id' => ['bail', 'sometimes', 'required', 'uuid'],
            'title' => ['sometimes', 'required', 'string', 'min:3', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'quality_grade' => ['sometimes', 'nullable', Rule::enum(QualityGrade::class)],
            'offers_pickup' => ['sometimes', 'boolean'],
            'offers_delivery' => ['sometimes', 'boolean'],
            'low_stock_threshold' => ['sometimes', 'nullable', DecimalString::quantity()],
            // Changing the unit would change the meaning of every recorded quantity.
            'stock_unit' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'stock_unit.prohibited' => __('The stock unit cannot be changed. Create a new listing to count stock differently.'),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $errors = $validator->errors();
                $product = $this->product();
                $farm = $product->farm;

                if ($this->has('category_id')) {
                    ProductRules::checkCategory($errors, $this->input('category_id'));
                }

                if ($this->has('farm_location_id')) {
                    ProductRules::checkLocation($errors, $farm, $this->input('farm_location_id'));
                }

                if ($this->hasAny(['offers_pickup', 'offers_delivery'])) {
                    ProductRules::checkFulfilment(
                        $errors,
                        $farm,
                        $this->boolean('offers_pickup', $product->offers_pickup),
                        $this->boolean('offers_delivery', $product->offers_delivery),
                    );
                }

                ProductRules::checkWholeStock($errors, $product->stockUnit, [
                    'low_stock_threshold' => $this->input('low_stock_threshold'),
                ]);
            },
        ];
    }

    private function product(): Product
    {
        return $this->route('product');
    }
}
