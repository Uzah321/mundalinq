<?php

namespace App\Http\Controllers\Api\V1\Orders;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SellerOrder;
use App\Services\Orders\OrderDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

/**
 * Receipts for buyers and sale records for farms, as PDFs. Apps that open the PDF in the phone's
 * browser ask for a short-lived signed link first, since the browser has no sign-in token.
 */
class OrderDocumentController extends Controller
{
    private const LINK_MINUTES = 15;

    public function __construct(private OrderDocuments $documents) {}

    public function receipt(Order $order): Response
    {
        Gate::authorize('view', $order);

        return $this->pdf($this->documents->receipt($order), "MundaLinq-{$order->number}.pdf");
    }

    public function receiptLink(Order $order): JsonResponse
    {
        Gate::authorize('view', $order);

        return $this->link('documents.receipt', ['order' => $order]);
    }

    public function signedReceipt(Order $order): Response
    {
        return $this->pdf($this->documents->receipt($order), "MundaLinq-{$order->number}.pdf");
    }

    public function farmSale(SellerOrder $sellerOrder): Response
    {
        Gate::authorize('fulfil', $sellerOrder);

        return $this->pdf($this->documents->farmSale($sellerOrder), "MundaLinq-{$sellerOrder->number}.pdf");
    }

    public function farmSaleLink(SellerOrder $sellerOrder): JsonResponse
    {
        Gate::authorize('fulfil', $sellerOrder);

        return $this->link('documents.farm-sale', ['sellerOrder' => $sellerOrder]);
    }

    public function signedFarmSale(SellerOrder $sellerOrder): Response
    {
        return $this->pdf($this->documents->farmSale($sellerOrder), "MundaLinq-{$sellerOrder->number}.pdf");
    }

    /**
     * A relative signed path: it works whichever address the app reaches the server on.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function link(string $route, array $parameters): JsonResponse
    {
        $path = URL::temporarySignedRoute('v1.'.$route, now()->addMinutes(self::LINK_MINUTES), $parameters, absolute: false);

        return response()->json(['data' => ['path' => $path, 'expires_in_minutes' => self::LINK_MINUTES]]);
    }

    private function pdf(string $bytes, string $filename): Response
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
