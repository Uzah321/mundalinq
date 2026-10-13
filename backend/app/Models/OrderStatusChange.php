<?php

namespace App\Models;

use BackedEnum;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only history of every status change on an order or one of its farm orders.
 */
class OrderStatusChange extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'order_status_history';

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public static function record(
        Order|string $order,
        ?SellerOrder $sellerOrder,
        ?BackedEnum $from,
        BackedEnum $to,
        ?User $actor = null,
        ?string $note = null,
    ): self {
        return static::forceCreate([
            'order_id' => $order instanceof Order ? $order->id : $order,
            'seller_order_id' => $sellerOrder?->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_id' => $actor?->id,
            'note' => $note,
        ]);
    }
}
