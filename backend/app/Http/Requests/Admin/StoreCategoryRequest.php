<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'slug' => ['nullable', 'string', 'max:100', 'alpha_dash', Rule::unique('categories', 'slug')],
            // Categories are two levels deep: a parent must itself be top-level.
            'parent_id' => ['bail', 'nullable', 'uuid', Rule::exists('categories', 'id')->whereNull('parent_id')],
            'description' => ['nullable', 'string', 'max:255'],
            'requires_approval' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'parent_id.exists' => __('Choose a top-level category as the parent.'),
        ];
    }
}
