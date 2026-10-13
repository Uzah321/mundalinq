<?php

namespace App\Http\Requests\Farms;

use Illuminate\Validation\Rule;

final class LocationRules
{
    /**
     * Validation rules for a farm location, optionally nested under a key (e.g. "location.").
     * When $partial is true every field becomes optional, for PATCH requests.
     */
    public static function for(string $prefix = '', bool $partial = false): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        return [
            $prefix.'name' => [...($partial ? ['sometimes'] : []), 'nullable', 'string', 'max:100'],
            $prefix.'province' => [...$required, 'string', Rule::in(config('mundalinq.countries.ZW.provinces'))],
            $prefix.'district' => [...$required, 'string', 'max:80'],
            $prefix.'ward' => ['sometimes', 'nullable', 'string', 'max:80'],
            $prefix.'address_line' => ['sometimes', 'nullable', 'string', 'max:255'],
            $prefix.'latitude' => [...$required, 'numeric', 'between:-90,90'],
            $prefix.'longitude' => [...$required, 'numeric', 'between:-180,180'],
        ];
    }
}
