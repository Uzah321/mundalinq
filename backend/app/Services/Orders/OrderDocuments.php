<?php

namespace App\Services\Orders;

use App\Enums\LedgerEntryType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\SellerOrder;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PDF paperwork: the buyer's receipt (or order summary before payment) and each farm's sale record.
 */
class OrderDocuments
{
    /**
     * The whole order for the buyer: a receipt once paid, otherwise a summary marked unpaid.
     */
    public function receipt(Order $order): string
    {
        $order->loadMissing(['buyer', 'sellerOrders.farm.primaryLocation', 'sellerOrders.items', 'payments']);

        return $this->render('documents.receipt', [
            'order' => $order,
            'payment' => $order->payments->where('status', PaymentStatus::Succeeded)->sortByDesc('paid_at')->first(),
            'title' => match ($order->status) {
                OrderStatus::Paid, OrderStatus::Completed => __('Receipt'),
                OrderStatus::Cancelled => __('Cancelled order'),
                default => __('Order summary'),
            },
        ]);
    }

    /**
     * One farm's part of an order for its records: what was sold, MundaLinq's fee and what the farm receives.
     */
    public function farmSale(SellerOrder $sellerOrder): string
    {
        $sellerOrder->loadMissing(['farm.primaryLocation', 'items', 'order.buyer']);
        $ledger = LedgerEntry::where('seller_order_id', $sellerOrder->id)->get();
        $fees = -(int) $ledger->where('type', LedgerEntryType::Commission)->sum('amount_minor');
        $refunds = -(int) $ledger->where('type', LedgerEntryType::Refund)->sum('amount_minor');

        return $this->render('documents.farm-sale', [
            'part' => $sellerOrder,
            'order' => $sellerOrder->order,
            'paid' => $ledger->contains('type', LedgerEntryType::Sale),
            'fees' => $fees,
            'refunds' => $refunds,
            'receives' => $sellerOrder->total_minor - $fees - $refunds,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(string $view, array $data): string
    {
        // Nothing outside the document is fetched: no remote images or stylesheets.
        $pdf = new Dompdf(new Options(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]));
        $pdf->loadHtml(view($view, $data)->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }
}
