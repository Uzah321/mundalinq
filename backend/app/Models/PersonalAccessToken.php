<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * One row per signed-in device. UUID keys keep token ids opaque in the API.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use HasUuids;

    /**
     * Reject malformed "{id}|{secret}" tokens before PostgreSQL sees a non-UUID id.
     */
    public static function findToken($token)
    {
        if (str_contains($token, '|') && ! Str::isUuid(Str::before($token, '|'))) {
            return null;
        }

        return parent::findToken($token);
    }
}
