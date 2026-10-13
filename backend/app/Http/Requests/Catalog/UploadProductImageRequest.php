<?php

namespace App\Http\Requests\Catalog;

use App\Rules\PhotoWithinMegapixels;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UploadProductImageRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('manage', $this->route('product'));
    }

    public function rules(): array
    {
        return [
            'image' => [
                'bail',
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp',
                'max:'.config('mundalinq.catalog.image_max_kb'),
                new PhotoWithinMegapixels(config('mundalinq.catalog.image_max_megapixels')),
            ],
        ];
    }
}
