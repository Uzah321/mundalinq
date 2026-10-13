<?php

namespace App\Models;

use App\Enums\FulfilmentMethod;
use App\Enums\SellerOrderStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * One farm's part of an order. Written only by the checkout and order services.
 */
#[Hidden(['handover_code'])]
class SellerOrder extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'status' => SellerOrderStatus::class,
            'fulfilment_method' => FulfilmentMethod::class,
            'delivery_address' => 'array',
            'subtotal_minor' => 'integer',
            'delivery_fee_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'handover_code' => 'encrypted',
            'confirm_by' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'ready_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('created_at')->orderBy('id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusChange::class)->orderBy('created_at')->orderBy('id');
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function dispute(): HasOne
    {
        return $this->hasOne(Dispute::class);
    }

    /**
     * When the buyer stops being able to report a problem (null before handover).
     */
    public function disputeDeadline(): ?CarbonInterface
    {
        return $this->delivered_at?->copy()->addHours(config('mundalinq.orders.dispute_window_hours'));
    }

    public function isPickup(): bool
    {
        return $this->fulfilment_method === FulfilmentMethod::Pickup;
    }

    /**
     * The order has been paid for and the farm is (or was) fulfilling it.
     */
    public function isPaid(): bool
    {
        return in_array($this->status, SellerOrderStatus::paid(), true);
    }

    public function matchesHandoverCode(string $code): bool
    {
        return hash_equals((string) $this->handover_code, $code);
    }

    public function transitionTo(SellerOrderStatus $status, ?User $actor = null, ?string $note = null): void
    {
        $from = $this->status;

        if (! in_array($status, $from->allowedNext(), true)) {
            throw new LogicException("Seller order {$this->number} cannot go from {$from->value} to {$status->value}.");
        }

        $this->status = $status;

        if ($column = $status->timestampColumn()) {
            $this->{$column} = now();
        }

        $this->save();

        OrderStatusChange::record($this->order_id, $this, $from, $status, $actor, $note);
    }
}
