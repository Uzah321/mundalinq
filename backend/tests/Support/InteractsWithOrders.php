<?php

namespace Tests\Support;

use App\Enums\PaymentMethod;
use App\Enums\RoleSlug;
use App\Models\Farm;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\Cart\CartService;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\QuoteBuilder;
use App\Services\Orders\SellerOrderService;
use App\Services\Payments\Gateways\SandboxGateway;
use App\Services\Payments\PaymentService;
use App\Support\Money;

/**
 * Shortcuts through the buying flow, using the same services as the API.
 */
trait InteractsWithOrders
{
    use InteractsWithCatalog;

    protected function buyer(): User
    {
        return User::factory()->withRole(RoleSlug::Buyer)->create();
    }

    /**
     * A verified farm in Ruwa (about 21 km from central Harare) offering pickup, and delivery
     * within 50 km for $2.00, free from $50.
     *
     * @return array{0: User, 1: Farm}
     */
    protected function deliveringFarm(): array
    {
        [$farmer, $farm] = $this->farmerWithFarm([
            'offers_delivery' => true,
            'delivery_radius_km' => 50,
            'delivery_fee_minor' => 200,
            'free_delivery_over_minor' => 5000,
        ]);
        $farm->primaryLocation->update(['latitude' => -17.8897, 'longitude' => 31.2447]);

        return [$farmer, $farm];
    }

    /**
     * A live listing with one selling option in its stock unit.
     */
    protected function listing(Farm $farm, string $stock = '100', string $unit = 'kg', string $price = '1.20', array $attributes = []): ProductVariant
    {
        $product = Product::factory()->active()->withImage()->withVariant($unit, $price)->withStock($stock)->create([
            'farm_id' => $farm->id,
            'stock_unit_id' => Unit::idFor($unit),
            'offers_delivery' => $farm->offers_delivery,
            ...$attributes,
        ]);

        return $product->variants()->firstOrFail();
    }

    protected function harareAddress(User $buyer): UserAddress
    {
        return UserAddress::factory()->for($buyer)->create();
    }

    protected function addToCart(User $buyer, ProductVariant $variant, string $quantity): void
    {
        app(CartService::class)->put($buyer, $variant, $quantity);
    }

    /**
     * Place whatever is in the cart, confirming the total the quote shows, as the app does.
     */
    protected function checkout(User $buyer, array $fulfilment = [], ?UserAddress $address = null): Order
    {
        $quote = app(QuoteBuilder::class)->build(app(CartService::class)->for($buyer), $buyer, true, $address, $fulfilment);

        return app(CheckoutService::class)->placeOrder($buyer, $address, $fulfilment, Money::format($quote->totalMinor()));
    }

    protected function acceptAll(Order $order): Order
    {
        foreach ($order->sellerOrders()->with('farm.members')->get() as $sellerOrder) {
            app(SellerOrderService::class)->accept($sellerOrder, $sellerOrder->farm->members->first());
        }

        return $order->refresh();
    }

    protected function pay(Order $order, string $phone = SandboxGateway::SUCCESS): Payment
    {
        $payments = app(PaymentService::class);

        return $payments->refresh($payments->initiate($order, $order->buyer, PaymentMethod::MobileMoney, $phone));
    }

    /**
     * Cart, order, accepted by every farm and paid.
     */
    protected function paidOrder(User $buyer, ProductVariant $variant, string $quantity = '10', array $fulfilment = [], ?UserAddress $address = null): Order
    {
        $this->addToCart($buyer, $variant, $quantity);
        $order = $this->acceptAll($this->checkout($buyer, $fulfilment, $address));
        $this->pay($order);

        return $order->refresh();
    }

    /**
     * @return list<string> Messages sent to a phone number.
     */
    protected function smsTo(string $phone): array
    {
        return array_values(array_map(
            fn (array $sms) => $sms['message'],
            array_filter($this->sms->messages, fn (array $sms) => $sms['phone'] === $phone),
        ));
    }
}
