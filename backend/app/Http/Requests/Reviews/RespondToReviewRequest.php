<?php

namespace App\Http\Requests\Reviews;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class RespondToReviewRequest extends FormRequest
{
    /**
     * The farm's owners and managers.
     */
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('review')->farm);
    }

    public function rules(): array
    {
        return [
            'response' => ['required', 'string', 'min:2', 'max:1000'],
        ];
    }
}
