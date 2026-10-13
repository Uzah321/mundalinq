<?php

namespace App\Http\Requests\Admin;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'slug' => ['sometimes', 'required', 'string', 'max:100', 'alpha_dash', Rule::unique('categories', 'slug')->ignore($this->category()->id)],
            'parent_id' => ['bail', 'sometimes', 'nullable', 'uuid', Rule::exists('categories', 'id')->whereNull('parent_id')],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
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

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $parentId = $this->input('parent_id');

                if ($parentId === null) {
                    return;
                }

                if ($parentId === $this->category()->id) {
                    $validator->errors()->add('parent_id', __('A category cannot be its own parent.'));
                } elseif ($this->category()->children()->exists()) {
                    $validator->errors()->add('parent_id', __('A category with subcategories must stay top-level.'));
                }
            },
        ];
    }

    private function category(): Category
    {
        return $this->route('category');
    }
}
