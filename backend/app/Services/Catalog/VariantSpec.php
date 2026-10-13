<?php

namespace App\Services\Catalog;

use App\Models\ProductVariant;
use App\Models\Unit;
use App\Support\Money;
use App\Support\Quantity;
use Brick\Math\BigDecimal;

/**
 * Turns a selling option from API input into stored attributes and checks the rules that span
 * several fields: how much stock one sale unit uses, whole-number units, and bulk price tiers.
 *
 * Input must already have passed the shape rules in VariantRules.
 */
final class VariantSpec
{
    /** @var array<string, string> Field relative to the variant => message. */
    private array $errors = [];

    private ?Unit $unit = null;

    private ?BigDecimal $baseQuantity = null;

    private int $priceMinor = 0;

    private BigDecimal $minOrderQuantity;

    private ?BigDecimal $maxOrderQuantity = null;

    private ?string $label = null;

    private bool $isActive = true;

    /** @var list<array{min_quantity: string, price_minor: int}>|null Null leaves stored tiers untouched. */
    private ?array $tiers = null;

    /**
     * @param  array<string, mixed>  $input  Fields given by the client (all optional when updating).
     * @param  Unit  $stockUnit  The unit the product's stock is counted in.
     * @param  ProductVariant|null  $existing  The variant being updated, if any.
     */
    public function __construct(array $input, private Unit $stockUnit, private ?ProductVariant $existing = null)
    {
        $this->unit = array_key_exists('unit', $input) ? Unit::findByCode($input['unit']) : $existing->unit;
        $this->label = array_key_exists('label', $input) ? self::blankToNull($input['label']) : $existing?->label;
        $this->isActive = array_key_exists('is_active', $input) ? (bool) $input['is_active'] : ($existing?->is_active ?? true);
        $this->priceMinor = array_key_exists('price', $input) ? Money::toMinor($input['price']) : $existing->price_minor;

        $this->resolveBaseQuantity($input);
        $this->resolveOrderLimits($input);
        $this->resolveTiers($input);
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Identifies the selling option, so a product cannot sell "1 crate of 28 kg" twice.
     */
    public function key(): string
    {
        return self::keyFor($this->unit->id, $this->baseQuantity ?? BigDecimal::zero());
    }

    public static function keyFor(string $unitId, BigDecimal|string $baseQuantity): string
    {
        return $unitId.'|'.Quantity::format($baseQuantity);
    }

    public function attributes(): array
    {
        return [
            'unit_id' => $this->unit->id,
            'label' => $this->label,
            'base_quantity' => (string) $this->baseQuantity,
            'price_minor' => $this->priceMinor,
            'min_order_quantity' => (string) $this->minOrderQuantity,
            'max_order_quantity' => $this->maxOrderQuantity === null ? null : (string) $this->maxOrderQuantity,
            'is_active' => $this->isActive,
        ];
    }

    /**
     * @return list<array{min_quantity: string, price_minor: int}>|null
     */
    public function tiers(): ?array
    {
        return $this->tiers;
    }

    private static function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function resolveBaseQuantity(array $input): void
    {
        $given = isset($input['base_quantity']) ? Quantity::of($input['base_quantity']) : null;
        $unitChanged = $this->existing === null || $this->existing->unit_id !== $this->unit->id;
        $replacements = ['unit' => $this->unit->name, 'stock' => $this->stockUnit->plural_name];

        if ($this->unit->id === $this->stockUnit->id) {
            $expected = Quantity::of(1);
        } elseif ($this->unit->isConvertibleTo($this->stockUnit)) {
            $expected = $this->unit->conversionFactorTo($this->stockUnit);

            if ($expected->isZero()) {
                $this->errors['unit'] = __('A :unit is too small to sell when stock is counted in :stock.', $replacements);

                return;
            }
        } else {
            // Packages (crate, bag, tray...) hold an amount only the farmer knows.
            $base = $given ?? ($unitChanged ? null : Quantity::of($this->existing->base_quantity));

            if ($base === null) {
                $this->errors['base_quantity'] = __('Say how many :stock are in one :unit.', $replacements);
            } elseif (! $this->stockUnit->allows_fraction && ! Quantity::isWhole($base)) {
                $this->errors['base_quantity'] = __('One :unit must hold a whole number of :stock.', $replacements);
            } else {
                $this->baseQuantity = $base;
            }

            return;
        }

        if ($given !== null && ! $given->isEqualTo($expected)) {
            $this->errors['base_quantity'] = __('One :unit is always :amount :stock.', [
                ...$replacements,
                'amount' => Quantity::format($expected),
            ]);

            return;
        }

        $this->baseQuantity = $expected;
    }

    private function resolveOrderLimits(array $input): void
    {
        $this->minOrderQuantity = match (true) {
            isset($input['min_order_quantity']) => Quantity::of($input['min_order_quantity']),
            $this->existing !== null => Quantity::of($this->existing->min_order_quantity),
            default => Quantity::of(1),
        };

        $this->maxOrderQuantity = match (true) {
            array_key_exists('max_order_quantity', $input) => $input['max_order_quantity'] === null ? null : Quantity::of($input['max_order_quantity']),
            $this->existing?->max_order_quantity !== null => Quantity::of($this->existing->max_order_quantity),
            default => null,
        };

        if (! $this->unit->allows_fraction) {
            if (! Quantity::isWhole($this->minOrderQuantity)) {
                $this->errors['min_order_quantity'] = __('Orders are in whole :units.', ['units' => $this->unit->plural_name]);
            }

            if ($this->maxOrderQuantity !== null && ! Quantity::isWhole($this->maxOrderQuantity)) {
                $this->errors['max_order_quantity'] = __('Orders are in whole :units.', ['units' => $this->unit->plural_name]);
            }
        }

        if ($this->maxOrderQuantity !== null && $this->maxOrderQuantity->isLessThan($this->minOrderQuantity)) {
            $this->errors['max_order_quantity'] = __('The maximum order cannot be below the minimum order.');
        }
    }

    private function resolveTiers(array $input): void
    {
        $explicit = array_key_exists('price_tiers', $input);

        $tiers = $explicit
            ? array_values(array_filter((array) ($input['price_tiers'] ?? []), 'is_array'))
            : ($this->existing?->priceTiers->map(fn ($tier) => [
                'min_quantity' => $tier->min_quantity,
                'price' => Money::format($tier->price_minor),
            ])->all() ?? []);

        // Validate in quantity order but report against the client's own indexes.
        $order = array_keys($tiers);
        usort($order, fn (int $a, int $b) => Quantity::of($tiers[$a]['min_quantity'])->compareTo(Quantity::of($tiers[$b]['min_quantity'])));

        $previousQuantity = $this->minOrderQuantity;
        $previousPrice = $this->priceMinor;
        $normalized = [];

        foreach ($order as $position => $index) {
            // Stored tiers re-checked after a price or limit change are reported as a whole.
            $key = fn (string $attribute) => $explicit ? "price_tiers.{$index}.{$attribute}" : 'price_tiers';
            $minQuantity = Quantity::of($tiers[$index]['min_quantity']);
            $priceMinor = Money::toMinor($tiers[$index]['price']);

            if (! $this->unit->allows_fraction && ! Quantity::isWhole($minQuantity)) {
                $this->errors[$key('min_quantity')] = __('Orders are in whole :units.', ['units' => $this->unit->plural_name]);
            } elseif (! $minQuantity->isGreaterThan($previousQuantity)) {
                $this->errors[$key('min_quantity')] = $position === 0
                    ? __('Bulk prices must start above the minimum order of :quantity.', ['quantity' => Quantity::format($this->minOrderQuantity)])
                    : __('Each bulk price must start at a larger quantity than the one before.');
            } elseif ($this->maxOrderQuantity !== null && $minQuantity->isGreaterThan($this->maxOrderQuantity)) {
                $this->errors[$key('min_quantity')] = __('A bulk price cannot start above the maximum order.');
            }

            if ($priceMinor >= $previousPrice) {
                $this->errors[$key('price')] = __('Each bulk price must be lower than the price before it.');
            }

            $previousQuantity = $minQuantity;
            $previousPrice = $priceMinor;
            $normalized[] = ['min_quantity' => (string) $minQuantity, 'price_minor' => $priceMinor];
        }

        if ($explicit) {
            $this->tiers = $normalized;
        }
    }
}
