<?php

namespace App\Models;

use App\Enums\CancellationReason;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Nothing here is mass assignable: orders are only ever written by the checkout and order services.
 */
class Order extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'cancellation_reason' => CancellationReason::class,
            'subtotal_minor' => 'integer',
            'delivery_fee_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'payment_due_at' => 'datetime',
            'paid_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function sellerOrders(): HasMany
    {
        return $this->hasMany(SellerOrder::class)->orderBy('number');
    }

    /**
     * Newest attempt first.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderBy('created_at')->orderBy('id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusChange::class)->orderBy('created_at')->orderBy('id');
    }

    public function transitionTo(OrderStatus $status, ?User $actor = null, ?string $note = null): void
    {
        $from = $this->status;

        if (! in_array($status, $from->allowedNext(), true)) {
            throw new LogicException("Order {$this->number} cannot go from {$from->value} to {$status->value}.");
        }

        $this->status = $status;

        if ($column = $status->timestampColumn()) {
            $this->{$column} = now();
        }

        $this->save();

        OrderStatusChange::record($this, null, $from, $status, $actor, $note);
    }
}
