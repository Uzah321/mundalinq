<?php

namespace App\Services\Disputes;

use App\Enums\DisputeParty;
use App\Enums\DisputeReason;
use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use App\Enums\PaymentStatus;
use App\Enums\SellerOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\DisputeMessage;
use App\Models\Order;
use App\Models\SellerOrder;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Orders\OrderService;
use App\Services\Payments\RefundService;
use App\Services\Settlement\SettlementService;
use App\Support\Images\ImageProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Problems buyers report with delivered orders. While a dispute is open the farm order stays
 * "disputed" and its earnings pending; support's decision completes the sale, with any refund
 * taken from the farm's earnings and sent back to the buyer.
 *
 * Lock order matches the order services: order, seller order, then dispute.
 */
class DisputeService
{
    public function __construct(
        private OrderService $orders,
        private SettlementService $settlement,
        private RefundService $refunds,
        private ImageProcessor $images,
        private AuditLogger $audit,
        private DisputeNotifier $notifier,
    ) {}

    public function open(SellerOrder $sellerOrder, User $buyer, DisputeReason $reason, string $description, ?int $requestedRefundMinor): Dispute
    {
        return DB::transaction(function () use ($sellerOrder, $buyer, $reason, $description, $requestedRefundMinor) {
            $this->orders->lock($sellerOrder->order_id);
            $sellerOrder = SellerOrder::whereKey($sellerOrder->id)->lockForUpdate()->firstOrFail();

            if ($sellerOrder->status !== SellerOrderStatus::Delivered) {
                throw new BusinessRuleException(match ($sellerOrder->status) {
                    SellerOrderStatus::Disputed => __('A problem has already been reported for this order.'),
                    SellerOrderStatus::Completed => __('This order is complete, so problems can no longer be reported.'),
                    default => __('You can report a problem once the order has been handed over.'),
                });
            }

            if ($sellerOrder->disputeDeadline()->isPast()) {
                throw new BusinessRuleException(__('The time to report a problem with this order has passed.'));
            }

            if ($requestedRefundMinor !== null && $requestedRefundMinor > $sellerOrder->total_minor) {
                throw new BusinessRuleException(__('You cannot ask for more than the order cost.'), 422);
            }

            $sellerOrder->transitionTo(SellerOrderStatus::Disputed, $buyer, $reason->value);

            $dispute = Dispute::forceCreate([
                'seller_order_id' => $sellerOrder->id,
                'order_id' => $sellerOrder->order_id,
                'farm_id' => $sellerOrder->farm_id,
                'buyer_id' => $buyer->id,
                'reason' => $reason,
                'description' => $description,
                'requested_refund_minor' => $requestedRefundMinor,
                'status' => DisputeStatus::Open,
            ]);

            DB::afterCommit(fn () => $this->notifier->opened($dispute));

            return $dispute;
        });
    }

    public function addMessage(Dispute $dispute, User $author, DisputeParty $party, string $body): DisputeMessage
    {
        $this->ensureOpen($dispute);

        $message = $dispute->messages()->forceCreate([
            'author_id' => $author->id,
            'author_party' => $party,
            'body' => $body,
        ]);

        $this->notifier->messageAdded($dispute, $message);

        return $message;
    }

    /**
     * Photos are re-encoded before storage, which also strips location metadata.
     */
    public function addEvidence(Dispute $dispute, User $uploader, DisputeParty $party, UploadedFile $photo): DisputeEvidence
    {
        $this->ensureOpen($dispute);
        $this->ensureRoomForEvidence($dispute);

        $image = $this->images->resize($photo->getRealPath(), ['large' => config('mundalinq.disputes.evidence_max_edge')])['large'];
        $disk = config('mundalinq.disputes.evidence_disk');
        $path = "disputes/{$dispute->id}/".Str::uuid7().'.'.$image->extension;

        Storage::disk($disk)->put($path, $image->data);

        try {
            return DB::transaction(function () use ($dispute, $uploader, $party, $disk, $path, $image) {
                $dispute = Dispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();
                $this->ensureOpen($dispute);
                $this->ensureRoomForEvidence($dispute);

                return $dispute->evidence()->forceCreate([
                    'uploaded_by' => $uploader->id,
                    'uploader_party' => $party,
                    'disk' => $disk,
                    'path' => $path,
                    'mime_type' => $image->extension === 'webp' ? 'image/webp' : 'image/jpeg',
                    'size_bytes' => strlen($image->data),
                ]);
            });
        } catch (Throwable $e) {
            Storage::disk($disk)->delete($path);

            throw $e;
        }
    }

    /**
     * The buyer settled it with the farm: the sale completes as if nothing was reported.
     */
    public function withdraw(Dispute $dispute, User $buyer): Dispute
    {
        return $this->locked($dispute, function (Order $order, SellerOrder $sellerOrder, Dispute $dispute) use ($buyer) {
            $dispute->forceFill(['status' => DisputeStatus::Withdrawn, 'withdrawn_at' => now()])->save();
            $this->orders->complete($order, $sellerOrder, $buyer);

            DB::afterCommit(fn () => $this->notifier->withdrawn($dispute));
        });
    }

    /**
     * Support's decision. A refund comes out of the farm's earnings for the order (with the platform
     * fee on refunded goods returned) and is sent back to the buyer's payment.
     */
    public function resolve(Dispute $dispute, User $staff, DisputeResolution $resolution, ?int $partialRefundMinor, string $note): Dispute
    {
        return $this->locked($dispute, function (Order $order, SellerOrder $sellerOrder, Dispute $dispute) use ($staff, $resolution, $partialRefundMinor, $note) {
            $refundMinor = match ($resolution) {
                DisputeResolution::RefundFull => $sellerOrder->total_minor,
                DisputeResolution::RefundPartial => (int) $partialRefundMinor,
                DisputeResolution::NoRefund => 0,
            };

            if ($resolution === DisputeResolution::RefundPartial && ($refundMinor <= 0 || $refundMinor >= $sellerOrder->total_minor)) {
                throw new BusinessRuleException(__('A partial refund must be more than zero and less than the order total. Use a full refund otherwise.'), 422);
            }

            if ($refundMinor > 0) {
                $payment = $order->payments()->where('status', PaymentStatus::Succeeded)->where('requires_refund', false)->firstOrFail();

                $this->settlement->recordRefund($sellerOrder, $payment, $refundMinor);
                $this->refunds->create($payment, $refundMinor, $staff, $sellerOrder, $dispute);
            }

            $dispute->forceFill([
                'status' => DisputeStatus::Resolved,
                'resolution' => $resolution,
                'refund_minor' => $refundMinor,
                'resolution_note' => $note,
                'resolved_by' => $staff->id,
                'resolved_at' => now(),
            ])->save();

            $this->orders->complete($order, $sellerOrder, $staff);

            $this->audit->record($staff, 'dispute.resolved', $dispute, [
                'resolution' => $resolution->value,
                'refund_minor' => $refundMinor,
                'seller_order_id' => $sellerOrder->id,
            ]);

            DB::afterCommit(fn () => $this->notifier->resolved($dispute));
        });
    }

    /**
     * @param  callable(Order, SellerOrder, Dispute): void  $change
     */
    private function locked(Dispute $dispute, callable $change): Dispute
    {
        return DB::transaction(function () use ($dispute, $change) {
            $order = $this->orders->lock($dispute->order_id);
            $sellerOrder = SellerOrder::whereKey($dispute->seller_order_id)->lockForUpdate()->firstOrFail();
            $dispute = Dispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();

            $this->ensureOpen($dispute);
            $change($order, $sellerOrder, $dispute);

            return $dispute;
        });
    }

    private function ensureOpen(Dispute $dispute): void
    {
        if (! $dispute->isOpen()) {
            throw new BusinessRuleException(__('This report has been closed.'));
        }
    }

    private function ensureRoomForEvidence(Dispute $dispute): void
    {
        if ($dispute->evidence()->count() >= config('mundalinq.disputes.max_evidence')) {
            throw new BusinessRuleException(__('A report can have at most :max photos.', ['max' => config('mundalinq.disputes.max_evidence')]), 422);
        }
    }
}
