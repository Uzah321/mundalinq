<?php

namespace App\Http\Requests\Disputes;

use App\Rules\PhotoWithinMegapixels;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UploadDisputeEvidenceRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('view', $this->route('dispute'));
    }

    public function rules(): array
    {
        return [
            'photo' => [
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
