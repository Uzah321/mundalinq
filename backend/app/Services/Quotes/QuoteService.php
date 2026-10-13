<?php

namespace App\Services\Quotes;

use App\Enums\FulfilmentMethod;
use App\Enums\NotificationCategory;
use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Enums\QuoteOfferStatus;
use App\Enums\QuoteRequestStatus;
use App\Enums\SellerOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Category;
use App\Models\Farm;
use App\Models\Order;
use App\Models\OrderStatusChange;
use App\Models\ProductVariant;
use App\Models\QuoteOffer;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\UserAddress;
use App\Notifications\MarketplaceAlert;
use App\Services\Inventory\InventoryService;
use App\Services\Orders\OrderNotifier;
use App\Support\Money;
use App\Support\OrderNumber;
use App\Support\Quantity;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Requests for quotes: buyers ask for a price, matching farms offer one, and the buyer's choice becomes
 * an ordinary order that the farm confirms, the buyer pays for and the farm hands over.
 */
class QuoteService
{
    public function __construct(private InventoryService $inventory, private OrderNotifier $orders) {}

    /**
     * Posts the request and tells the matching farms.
     *
     * @param  array<string, mixed>  $details  Validated: category, title, quantity, unit, frequency, dates and place.
     * @return array{0: QuoteRequest, 1: int} The request, and how many farms were told.
     */
    public function request(User $buyer, array $details, int $openDays): array
    {
        $request = QuoteRequest::forceCreate([
            ...$details,
            'buyer_id' => $buyer->id,
            'status' => QuoteRequestStatus::Open,
            'closes_at' => now()->addDays($openDays),
        ]);

        $request->load(['unit', 'category']);
        $quantity = Quantity::format($request->quantity).' '.((float) $request->quantity === 1.0 ? $request->unit->name : $request->unit->plural_name);
        $farms = $this->matchingFarms($request);

        foreach ($farms as $farm) {
            $alert = new MarketplaceAlert(
                NotificationCategory::Quotes,
                __('Buyer looking for :title', ['title' => $request->title]),
                __('A buyer in :district wants :quantity of :title, :frequency, from :date. Send your price in the app by :closes.', [
                    'district' => $request->district,
                    'quantity' => strtolower($quantity),
                    'title' => $request->title,
                    'frequency' => $request->frequency->label(),
                    'date' => $request->needed_by->format('j M'),
                    'closes' => $request->closes_at->format('j M'),
                ]),
                ['quote_request_id' => $request->id, 'farm_id' => $farm->id],
                sms: true,
            );

            foreach ($farm->members as $member) {
                $member->notify($alert);
            }
        }

        return [$request, $farms->count()];
    }

    /**
     * Verified farms in the buyer's province with a listing on sale in the category (or one under it).
     *
     * @return Collection<int, Farm>
     */
    public function matchingFarms(QuoteRequest $request): Collection
    {
        return Farm::approved()
            ->whereHas('primaryLocation', fn ($location) => $location->where('province', $request->province))
            ->whereHas('products', fn ($product) => $product->where('status', ProductStatus::Active)->whereIn('category_id', $this->withSubcategories($request->category)))
            ->whereDoesntHave('members', fn ($member) => $member->whereKey($request->buyer_id))
            ->with('members')
            ->get();
    }

    /**
     * Categories a farm's listings may sit in to match: the category, its subcategories and its parent.
     *
     * @return list<string>
     */
    public function withSubcategories(Category $category): array
    {
        return array_values(array_filter([$category->id, $category->parent_id, ...$category->children()->pluck('id')->all()]));
    }

    /**
     * The farm's price, new or changed. A withdrawn offer can be sent again; a decided one cannot.
     *
     * @param  array{product_variant_id: string, unit_price: string, quantity: string, delivery_fee?: ?string, fulfilment: string, message?: ?string, valid_until?: ?string}  $terms
     */
    public function offer(QuoteRequest $request, Farm $farm, User $actor, array $terms): QuoteOffer
    {
        [$offer, $isNew] = DB::transaction(function () use ($request, $farm, $actor, $terms) {
            $request = QuoteRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! $request->isOpen()) {
                throw new BusinessRuleException(__('This request is no longer taking offers.'));
            }

            if (! $farm->isApproved()) {
                throw new BusinessRuleException(__('Your farm must be verified before you can send offers.'), 422);
            }

            if ($farm->members()->whereKey($request->buyer_id)->exists()) {
                throw new BusinessRuleException(__('You cannot send an offer on your own request.'), 422);
            }

            $variant = ProductVariant::with('product')->find($terms['product_variant_id']);

            if ($variant === null || $variant->product?->farm_id !== $farm->id || $variant->product->status !== ProductStatus::Active) {
                throw new BusinessRuleException(__('Choose one of your listings that is on sale.'), 422);
            }

            $fulfilment = FulfilmentMethod::from($terms['fulfilment']);

            if ($request->fulfilment !== 'either' && $request->fulfilment !== $fulfilment->value) {
                throw new BusinessRuleException($request->fulfilment === 'delivery'
                    ? __('The buyer needs it delivered.')
                    : __('The buyer will collect it from the farm.'), 422);
            }

            $offer = QuoteOffer::where('quote_request_id', $request->id)->where('farm_id', $farm->id)->first()
                ?? (new QuoteOffer)->forceFill(['quote_request_id' => $request->id, 'farm_id' => $farm->id]);

            if ($offer->exists && in_array($offer->status, [QuoteOfferStatus::Accepted, QuoteOfferStatus::Declined], true)) {
                throw new BusinessRuleException(__('The buyer has already decided on your offer.'));
            }

            $isNew = ! $offer->exists || $offer->status === QuoteOfferStatus::Withdrawn;

            $offer->forceFill([
                'product_variant_id' => $variant->id,
                'unit_price_minor' => Money::toMinor($terms['unit_price']),
                'currency' => $variant->product->currency,
                'quantity' => $terms['quantity'],
                'delivery_fee_minor' => $fulfilment === FulfilmentMethod::Delivery ? Money::toMinor($terms['delivery_fee'] ?? '0') : 0,
                'fulfilment' => $fulfilment,
                'message' => $terms['message'] ?? null,
                'valid_until' => $terms['valid_until'] ?? null,
                'status' => QuoteOfferStatus::Pending,
                'created_by' => $actor->id,
                'decided_at' => null,
            ])->save();

            return [$offer, $isNew];
        });

        $offer->load(['quoteRequest.buyer', 'farm', 'variant.unit']);
        $request = $offer->quoteRequest;

        $request->buyer->notify(new MarketplaceAlert(
            NotificationCategory::Quotes,
            $isNew ? __('New offer from :farm', ['farm' => $farm->name]) : __(':farm changed its offer', ['farm' => $farm->name]),
            __(':farm offers :title at :price per :unit. Compare offers and choose one in MundaLinq.', [
                'farm' => $farm->name,
                'title' => $request->title,
                'price' => Money::display($offer->unit_price_minor, $offer->currency),
                'unit' => strtolower($offer->variant->unit->name),
            ]),
            ['quote_request_id' => $request->id],
            sms: $isNew,
        ));

        return $offer;
    }

    public function withdraw(QuoteOffer $offer): QuoteOffer
    {
        if ($offer->status !== QuoteOfferStatus::Pending) {
            throw new BusinessRuleException(__('Only offers the buyer has not decided on can be withdrawn.'));
        }

        $offer->forceFill(['status' => QuoteOfferStatus::Withdrawn, 'decided_at' => now()])->save();

        return $offer;
    }

    public function decline(QuoteOffer $offer): QuoteOffer
    {
        if ($offer->status !== QuoteOfferStatus::Pending) {
            throw new BusinessRuleException(__('This offer has already been decided.'));
        }

        $offer->forceFill(['status' => QuoteOfferStatus::Declined, 'decided_at' => now()])->save();
        $offer->loadMissing('quoteRequest');
        $this->tellFarm($offer, __('Offer not chosen'), __('The buyer looking for :title chose not to take your offer this time.', ['title' => $offer->quoteRequest->title]));

        return $offer;
    }

    /**
     * The buyer stops taking offers without choosing one.
     */
    public function close(QuoteRequest $request): QuoteRequest
    {
        if (! $request->isOpen()) {
            throw new BusinessRuleException(__('This request is already closed.'));
        }

        $pending = $request->offers()->where('status', QuoteOfferStatus::Pending)->get();

        DB::transaction(function () use ($request, $pending) {
            $request->forceFill(['status' => QuoteRequestStatus::Closed])->save();
            QuoteOffer::whereKey($pending->pluck('id'))->update(['status' => QuoteOfferStatus::Declined, 'decided_at' => now()]);
        });

        foreach ($pending as $offer) {
            $this->tellFarm($offer->setRelation('quoteRequest', $request), __('Request closed'), __('The buyer looking for :title closed the request without choosing an offer.', ['title' => $request->title]));
        }

        return $request;
    }

    /**
     * The buyer picks an offer: the others are declined and the first order goes to the farm to confirm.
     */
    public function accept(QuoteOffer $offer, User $buyer, ?UserAddress $address, ?string $note): Order
    {
        [$order, $declined] = DB::transaction(function () use ($offer, $buyer, $address, $note) {
            $request = QuoteRequest::whereKey($offer->quote_request_id)->lockForUpdate()->firstOrFail();
            $offer = QuoteOffer::whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if (! $request->isOpen() || $offer->status !== QuoteOfferStatus::Pending) {
                throw new BusinessRuleException(__('This offer can no longer be accepted.'));
            }

            $offer->forceFill(['status' => QuoteOfferStatus::Accepted, 'decided_at' => now()])->save();
            $declined = $request->offers()->whereKeyNot($offer->id)->where('status', QuoteOfferStatus::Pending)->get();
            QuoteOffer::whereKey($declined->pluck('id'))->update(['status' => QuoteOfferStatus::Declined, 'decided_at' => now()]);
            $request->forceFill(['status' => QuoteRequestStatus::Awarded])->save();

            return [$this->placeOrder($offer->setRelation('quoteRequest', $request), $buyer, $address, $note), $declined];
        });

        $this->orders->orderPlaced($order);
        $offer->refresh()->load('quoteRequest');
        $this->tellFarm($offer, __('Offer accepted'), __('The buyer accepted your offer for :title. Order :number is waiting for you to confirm.', [
            'title' => $offer->quoteRequest->title,
            'number' => $order->sellerOrders()->first()->number,
        ]));

        foreach ($declined as $other) {
            $this->tellFarm($other->setRelation('quoteRequest', $offer->quoteRequest), __('Offer not chosen'), __('The buyer looking for :title chose another offer.', ['title' => $offer->quoteRequest->title]));
        }

        return $order;
    }

    /**
     * Another order on an accepted offer, e.g. this week's delivery, at the agreed price.
     */
    public function orderAgain(QuoteOffer $offer, User $buyer, ?UserAddress $address, ?string $note): Order
    {
        $order = DB::transaction(function () use ($offer, $buyer, $address, $note) {
            $offer = QuoteOffer::whereKey($offer->id)->lockForUpdate()->with('quoteRequest')->firstOrFail();

            if (! $offer->canOrderAgain()) {
                throw new BusinessRuleException(__('This offer has ended. Post a new request or message the farm.'), 422);
            }

            return $this->placeOrder($offer, $buyer, $address, $note);
        });

        $this->orders->orderPlaced($order);

        return $order;
    }

    /**
     * An order like one from the basket, priced at the offer: the farm confirms it, then the buyer pays.
     */
    private function placeOrder(QuoteOffer $offer, User $buyer, ?UserAddress $address, ?string $note): Order
    {
        $variant = ProductVariant::with(['product', 'unit'])->findOrFail($offer->product_variant_id);
        $delivery = $offer->fulfilment === FulfilmentMethod::Delivery;

        if ($delivery && $address === null) {
            throw new BusinessRuleException(__('Choose the address the farm should deliver to.'), 422);
        }

        $quantity = Quantity::of((string) $offer->quantity);
        $stock = $quantity->multipliedBy($variant->base_quantity)->toScale(Quantity::SCALE, RoundingMode::Up);

        try {
            $this->inventory->reserve([$variant->product_id => $stock]);
        } catch (BusinessRuleException) {
            throw new BusinessRuleException(__(':farm does not have enough :title on sale right now. Message them to check.', [
                'farm' => $offer->farm()->value('name'),
                'title' => $variant->product->title,
            ]), 409);
        }

        $subtotal = Money::multiply($offer->unit_price_minor, $quantity);
        $deliveryFee = $offer->deliveryFeeMinor();

        $order = Order::forceCreate([
            'number' => OrderNumber::generate(),
            'buyer_id' => $buyer->id,
            'quote_offer_id' => $offer->id,
            'status' => OrderStatus::AwaitingConfirmation,
            'currency' => $offer->currency,
            'subtotal_minor' => $subtotal,
            'delivery_fee_minor' => $deliveryFee,
            'total_minor' => $subtotal + $deliveryFee,
            'note' => $note,
        ]);
        OrderStatusChange::record($order, null, null, OrderStatus::AwaitingConfirmation, $buyer);

        $sellerOrder = $order->sellerOrders()->forceCreate([
            'farm_id' => $offer->farm_id,
            'number' => $order->number.'-1',
            'status' => SellerOrderStatus::AwaitingConfirmation,
            'fulfilment_method' => $offer->fulfilment,
            'delivery_address' => $delivery ? $address->snapshot() : null,
            'subtotal_minor' => $subtotal,
            'delivery_fee_minor' => $deliveryFee,
            'total_minor' => $subtotal + $deliveryFee,
            'handover_code' => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'confirm_by' => now()->addMinutes(config('mundalinq.orders.confirm_within_minutes')),
        ]);
        OrderStatusChange::record($order, $sellerOrder, null, SellerOrderStatus::AwaitingConfirmation, $buyer);

        $sellerOrder->items()->forceCreate([
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->id,
            'product_title' => $variant->product->title,
            'variant_label' => $variant->label,
            'unit_code' => $variant->unit->code,
            'unit_name' => $variant->unit->name,
            'unit_plural_name' => $variant->unit->plural_name,
            'base_quantity' => $variant->base_quantity,
            'quantity' => (string) $quantity,
            'unit_price_minor' => $offer->unit_price_minor,
            'line_total_minor' => $subtotal,
            'stock_quantity' => (string) $stock,
        ]);

        return $order;
    }

    private function tellFarm(QuoteOffer $offer, string $title, string $body): void
    {
        $offer->loadMissing('farm.members');
        $alert = new MarketplaceAlert(NotificationCategory::Quotes, $title, $body, [
            'quote_request_id' => $offer->quote_request_id,
            'farm_id' => $offer->farm_id,
        ]);

        foreach ($offer->farm->members as $member) {
            $member->notify($alert);
        }
    }
}
