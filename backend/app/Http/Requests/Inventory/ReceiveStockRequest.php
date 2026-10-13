<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\Catalog\ProductRules;
use App\Models\Product;
use App\Rules\DecimalString;
use App\Support\Market;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class ReceiveStockRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('manage', $this->route('product'));
    }

    public function rules(): array
    {
        return [
            'quantity' => ['required', DecimalString::quantity()],
            'harvested_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.Market::today()],
            'best_before' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.Market::today()],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var Product $product */
                $product = $this->route('product');

                ProductRules::checkWholeStock($validator->errors(), $product->stockUnit, [
                    'quantity' => $this->input('quantity'),
                ]);
            },
        ];
    }
}
