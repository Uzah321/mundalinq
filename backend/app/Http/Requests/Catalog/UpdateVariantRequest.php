<?php

namespace App\Http\Requests\Catalog;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\VariantSpec;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class UpdateVariantRequest extends FormRequest
{
    private ?VariantSpec $spec = null;

    public function authorize(): Response
    {
        return Gate::inspect('manage', $this->product());
    }

    public function rules(): array
    {
        return VariantRules::for(partial: true);
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var ProductVariant $variant */
                $variant = $this->route('variant');
                $product = $this->product();

                $this->spec = new VariantSpec($this->all(), $product->stockUnit, $variant->load(['unit', 'priceTiers']));
                VariantChecks::addErrors($validator, $this->spec, $product, $variant);
            },
        ];
    }

    public function spec(): VariantSpec
    {
        return $this->spec;
    }

    private function product(): Product
    {
        return $this->route('product');
    }
}
