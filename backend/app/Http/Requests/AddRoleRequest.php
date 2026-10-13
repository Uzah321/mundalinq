<?php

namespace App\Http\Requests;

use App\Enums\RoleSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddRoleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(array_map(fn (RoleSlug $role) => $role->value, RoleSlug::selfRegistrable()))],
        ];
    }
}
