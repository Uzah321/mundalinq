<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The sandbox payment provider's side of a transaction. Only SandboxGateway touches this.
 */
#[Fillable(['reference', 'amount_minor', 'currency', 'phone', 'status'])]
class SandboxPayment extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
        ];
    }
}
