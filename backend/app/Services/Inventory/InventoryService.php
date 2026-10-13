<?php

namespace App\Services\Inventory;

use App\Enums\ProductStatus;
use App\Enums\StockAdjustmentDirection;
use App\Enums\StockAdjustmentReason;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\Market;
use App\Support\Quantity;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of stock. Every change locks the product row first (then any batch row), so stock
 * on hand always equals the sum of its batches and concurrent requests cannot interleave.
 */
class InventoryService
{
    public function __construct(private StockNotifier $notifier) {}

    /**
     * Record new stock arriving: a harvest or delivery batch.
     */
    public function receive(
        Product $product,
        ?User $actor,
        string $quantity,
        ?string $harvestedOn = null,
        ?string $bestBefore = null,
        ?string $notes = null,
    ): InventoryBatch {
        return DB::transaction(function () use ($product, $actor, $quantity, $harvestedOn, $bestBefore, $notes) {
            $product = $this->lockProduct($product->id);
            $quantity = Quantity::of($quantity);

            $batch = $product->batches()->create([
                'code' => $this->nextBatchCode($product),
                'harvested_on' => $harvestedOn,
                'best_before' => $bestBefore,
                'quantity_received' => (string) $quantity,
                'quantity_on_hand' => (string) $quantity,
                'notes' => $notes,
                'created_by' => $actor?->id,
            ]);

            $balance = Quantity::of($product->stock_on_hand)->plus($quantity);
            $product->stock_on_hand = (string) $balance;

            if ($harvestedOn !== null && ($product->last_harvested_on === null || $product->last_harvested_on->lt(Carbon::parse($harvestedOn)))) {
                $product->last_harvested_on = $harvestedOn;
            }

            $product->save();

            $this->record($product, $batch, StockMovementType::Receipt, $quantity, $balance, $actor);

            return $batch;
        });
    }

    /**
     * Correct a batch's count or record a loss such as spoilage.
     */
    public function adjust(
        InventoryBatch $batch,
        User $actor,
        StockAdjustmentDirection $direction,
        string $quantity,
        StockAdjustmentReason $reason,
        ?string $note = null,
    ): InventoryMovement {
        return DB::transaction(function () use ($batch, $actor, $direction, $quantity, $reason, $note) {
            $product = $this->lockProduct($batch->product_id);
            $batch = InventoryBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();

            $change = Quantity::of($quantity);
            if ($direction === StockAdjustmentDirection::Decrease) {
                $change = $change->negated();
            }

            $remaining = Quantity::of($batch->quantity_on_hand)->plus($change);

            if ($remaining->isNegative()) {
                throw new BusinessRuleException(__('Only :quantity :unit left in batch :code.', [
                    'quantity' => Quantity::format($batch->quantity_on_hand),
                    'unit' => $product->stockUnit->nameFor($batch->quantity_on_hand),
                    'code' => $batch->code,
                ]), 422);
            }

            $batch->forceFill(['quantity_on_hand' => (string) $remaining])->save();

            $wasLow = $product->isLowStock();
            $balance = Quantity::of($product->stock_on_hand)->plus($change);
            $product->forceFill(['stock_on_hand' => (string) $balance])->save();
            $this->alertIfNowLow($product, $wasLow);

            return $this->record($product, $batch, StockMovementType::Adjustment, $change, $balance, $actor, $reason, $note);
        });
    }

    /**
     * Hold stock for an order until it is handed over or given back. Products are locked in id order,
     * so concurrent checkouts queue rather than deadlock, and the same stock can never be promised twice.
     * Runs inside the caller's transaction.
     *
     * @param  array<string, BigDecimal>  $quantities  Product id => stock units.
     */
    public function reserve(array $quantities): void
    {
        $products = $this->lockProducts(array_keys($quantities));
        $shortages = [];

        foreach ($quantities as $productId => $quantity) {
            $product = $products->get($productId);
            $available = $product?->availableStock() ?? Quantity::of(0);

            if ($product === null || $product->trashed() || $product->status !== ProductStatus::Active || $available->isLessThan($quantity)) {
                $shortages[] = [
                    'product_id' => $productId,
                    'available' => Quantity::format($available->isNegative() ? Quantity::of(0) : $available),
                ];
            }
        }

        if ($shortages !== []) {
            throw new BusinessRuleException(
                __('Some items are no longer available in the quantity you chose. Review your cart and try again.'),
                409,
                ['shortages' => $shortages],
            );
        }

        foreach ($quantities as $productId => $quantity) {
            $product = $products->get($productId);
            $wasLow = $product->isLowStock();
            $product->forceFill(['stock_reserved' => (string) Quantity::of($product->stock_reserved)->plus($quantity)])->save();
            $this->alertIfNowLow($product, $wasLow);
        }
    }

    /**
     * Give reserved stock back, e.g. when a farm rejects an order or it is cancelled before handover.
     *
     * @param  array<string, BigDecimal>  $quantities  Product id => stock units.
     */
    public function release(array $quantities): void
    {
        $products = $this->lockProducts(array_keys($quantities));

        foreach ($quantities as $productId => $quantity) {
            $product = $products->get($productId);
            $reserved = Quantity::of($product->stock_reserved)->minus($quantity);

            $product->forceFill(['stock_reserved' => (string) ($reserved->isNegative() ? Quantity::of(0) : $reserved)])->save();
        }
    }

    /**
     * The goods leave the farm: take the item's reserved stock from the oldest harvest first and record the sale.
     */
    public function consume(OrderItem $item, ?User $actor): void
    {
        $quantity = Quantity::of($item->stock_quantity);

        if (! $quantity->isPositive()) {
            return;
        }

        $product = $this->lockProducts([$item->product_id])->get($item->product_id);

        if (Quantity::of($product->stock_on_hand)->isLessThan($quantity)) {
            $product->loadMissing('stockUnit');

            throw new BusinessRuleException(__('Your stock records show only :quantity :unit of :product. Record a stock correction before handing this order over.', [
                'quantity' => Quantity::format($product->stock_on_hand),
                'unit' => $product->stockUnit->nameFor($product->stock_on_hand),
                'product' => $product->title,
            ]));
        }

        $batches = $product->batches()
            ->where('quantity_on_hand', '>', 0)
            ->orderByRaw('harvested_on asc nulls last')
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $remaining = $quantity;
        $balance = Quantity::of($product->stock_on_hand);

        // Batches always add up to stock on hand, so this always covers the quantity.
        foreach ($batches as $batch) {
            if (! $remaining->isPositive()) {
                break;
            }

            $onHand = Quantity::of($batch->quantity_on_hand);
            $taken = $onHand->isLessThan($remaining) ? $onHand : $remaining;

            $batch->forceFill(['quantity_on_hand' => (string) $onHand->minus($taken)])->save();
            $balance = $balance->minus($taken);
            $remaining = $remaining->minus($taken);

            $this->record($product, $batch, StockMovementType::Sale, $taken->negated(), $balance, $actor, reference: $item);
        }

        $reserved = Quantity::of($product->stock_reserved)->minus($quantity);

        $product->forceFill([
            'stock_on_hand' => (string) $balance,
            'stock_reserved' => (string) ($reserved->isNegative() ? Quantity::of(0) : $reserved),
        ])->save();
    }

    /**
     * Alert the farm once, when a change takes a listing down to its low-stock level.
     */
    private function alertIfNowLow(Product $product, bool $wasLow): void
    {
        if (! $wasLow && $product->isLowStock()) {
            DB::afterCommit(fn () => $this->notifier->lowStock($product));
        }
    }

    private function lockProduct(string $productId): Product
    {
        return Product::whereKey($productId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Lock several products, always in id order. Archived products are included: their orders still settle.
     *
     * @param  list<string>  $ids
     * @return Collection<string, Product>
     */
    private function lockProducts(array $ids): Collection
    {
        sort($ids);

        return Product::withTrashed()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    /**
     * Readable, per-product batch codes such as B261012-01. Safe because the product row is locked.
     */
    private function nextBatchCode(Product $product): string
    {
        $prefix = 'B'.Carbon::parse(Market::today())->format('ymd');
        $sequence = $product->batches()->where('code', 'like', $prefix.'-%')->count() + 1;

        return sprintf('%s-%02d', $prefix, $sequence);
    }

    private function record(
        Product $product,
        InventoryBatch $batch,
        StockMovementType $type,
        BigDecimal $quantity,
        BigDecimal $balance,
        ?User $actor,
        ?StockAdjustmentReason $reason = null,
        ?string $note = null,
        ?Model $reference = null,
    ): InventoryMovement {
        return InventoryMovement::create([
            'product_id' => $product->id,
            'inventory_batch_id' => $batch->id,
            'type' => $type,
            'quantity' => (string) $quantity,
            'balance_after' => (string) $balance,
            'reason' => $reason,
            'note' => $note,
            'actor_id' => $actor?->id,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
        ]);
    }
}
