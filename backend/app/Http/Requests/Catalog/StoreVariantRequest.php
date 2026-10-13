<?php

namespace App\Http\Requests\Catalog;

use App\Models\Product;
use App\Services\Catalog\VariantSpec;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class StoreVariantRequest extends FormRequest
{
    private ?VariantSpec $spec = null;

    public function authorize(): Response
    {
        return Gate::inspect('manage', $this->product());
    }

    public function rules(): array
    {
        return VariantRules::for();
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $product = $this->product();

                if ($product->variants()->count() >= config('mundalinq.catalog.max_variants')) {
                    $validator->errors()->add('unit', __('A listing can have at most :max selling options.', [
                        'max' => config('mundalinq.catalog.max_variants'),
                    ]));

                    return;
                }

                $this->spec = new VariantSpec($this->all(), $product->stockUnit);
                VariantChecks::addErrors($validator, $this->spec, $product);
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
