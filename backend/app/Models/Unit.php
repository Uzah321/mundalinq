<?php

namespace App\Models;

use App\Enums\UnitMeasure;
use App\Support\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'plural_name', 'measure', 'base_factor', 'allows_fraction', 'sort_order'])]
class Unit extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'measure' => UnitMeasure::class,
            'base_factor' => 'decimal:6',
            'allows_fraction' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public static function findByCode(string $code): self
    {
        return static::where('code', $code)->firstOrFail();
    }

    public static function idFor(string $code): string
    {
        return static::where('code', $code)->valueOrFail('id');
    }

    /**
     * Mass converts to mass and volume to volume; count units (crate, bunch...) never convert by themselves.
     */
    public function isConvertibleTo(Unit $other): bool
    {
        return $this->measure === $other->measure && $this->measure !== UnitMeasure::Count;
    }

    /**
     * How many $other units make one of this unit, e.g. tonne -> kg = 1000. Zero if too small to express.
     */
    public function conversionFactorTo(Unit $other): BigDecimal
    {
        return BigDecimal::of($this->base_factor)->dividedBy($other->base_factor, Quantity::SCALE, RoundingMode::Down);
    }

    public function nameFor(BigDecimal|string $quantity): string
    {
        return BigDecimal::of($quantity)->isEqualTo(1) ? $this->name : $this->plural_name;
    }
}
