<?php

namespace App\Http\Requests\Catalog;

use App\Models\Product;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class ReorderProductImagesRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('manage', $this->route('product'));
    }

    public function rules(): array
    {
        return [
            'image_ids' => ['required', 'array'],
            'image_ids.*' => ['required', 'string', 'distinct'],
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
                $current = $product->images()->pluck('id')->sort()->values()->all();
                $given = collect($this->input('image_ids'))->sort()->values()->all();

                if ($current !== $given) {
                    $validator->errors()->add('image_ids', __('List every photo of this listing exactly once.'));
                }
            },
        ];
    }
}
