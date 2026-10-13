<?php

namespace App\Http\Requests\Catalog;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\VariantSpec;
use Illuminate\Validation\Validator;

final class VariantChecks
{
    /**
     * Report the spec's own errors, and refuse a selling option the product already has.
     */
    public static function addErrors(Validator $validator, VariantSpec $spec, Product $product, ?ProductVariant $ignore = null): void
    {
        foreach ($spec->errors() as $field => $message) {
            $validator->errors()->add($field, $message);
        }

        if (! $spec->passes()) {
            return;
        }

        $duplicate = $product->variants()
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->get(['id', 'unit_id', 'base_quantity'])
            ->contains(fn (ProductVariant $other) => VariantSpec::keyFor($other->unit_id, $other->base_quantity) === $spec->key());

        if ($duplicate) {
            $validator->errors()->add('unit', __('This listing already has this selling option.'));
        }
    }
}
