<?php

use App\Http\Controllers\Api\V1\AddressController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController as AdminAuditLogController;
use App\Http\Controllers\Api\V1\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\V1\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\V1\Admin\DisputeController as AdminDisputeController;
use App\Http\Controllers\Api\V1\Admin\FarmController as AdminFarmController;
use App\Http\Controllers\Api\V1\Admin\FarmVerificationController as AdminFarmVerificationController;
use App\Http\Controllers\Api\V1\Admin\FarmVerificationDocumentController as AdminFarmVerificationDocumentController;
use App\Http\Controllers\Api\V1\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\V1\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\V1\Admin\PayoutController as AdminPayoutController;
use App\Http\Controllers\Api\V1\Admin\ProductModerationController as AdminProductModerationController;
use App\Http\Controllers\Api\V1\Admin\RefundController as AdminRefundController;
use App\Http\Controllers\Api\V1\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\DeviceSessionController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\PhoneVerificationController;
use App\Http\Controllers\Api\V1\Cart\CartController;
use App\Http\Controllers\Api\V1\Cart\CartItemController;
use App\Http\Controllers\Api\V1\Catalog\CategoryController;
use App\Http\Controllers\Api\V1\Catalog\FarmProductController;
use App\Http\Controllers\Api\V1\Catalog\FavouriteController;
use App\Http\Controllers\Api\V1\Catalog\ProductController;
use App\Http\Controllers\Api\V1\Catalog\ProductImageController;
use App\Http\Controllers\Api\V1\Catalog\ProductLifecycleController;
use App\Http\Controllers\Api\V1\Catalog\ProductVariantController;
use App\Http\Controllers\Api\V1\Catalog\UnitController;
use App\Http\Controllers\Api\V1\Checkout\CheckoutController;
use App\Http\Controllers\Api\V1\Disputes\DisputeController;
use App\Http\Controllers\Api\V1\Farms\FarmAnalyticsController;
use App\Http\Controllers\Api\V1\Farms\FarmController;
use App\Http\Controllers\Api\V1\Farms\FarmEarningsController;
use App\Http\Controllers\Api\V1\Farms\FarmLocationController;
use App\Http\Controllers\Api\V1\Farms\FarmPayoutController;
use App\Http\Controllers\Api\V1\Farms\FarmVerificationController;
use App\Http\Controllers\Api\V1\Farms\MyFarmController;
use App\Http\Controllers\Api\V1\Inventory\InventoryAdjustmentController;
use App\Http\Controllers\Api\V1\Inventory\InventoryBatchController;
use App\Http\Controllers\Api\V1\Inventory\StockMovementController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\Messaging\ConversationController;
use App\Http\Controllers\Api\V1\MyRoleController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\Orders\FarmOrderController;
use App\Http\Controllers\Api\V1\Orders\OrderController;
use App\Http\Controllers\Api\V1\Orders\SellerOrderController;
use App\Http\Controllers\Api\V1\Payments\PaymentController;
use App\Http\Controllers\Api\V1\Payments\PaymentWebhookController;
use App\Http\Controllers\Api\V1\Reviews\ReviewController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function () {

    Route::prefix('auth')->name('auth.')->group(function () {
        Route::middleware('throttle:auth')->group(function () {
            Route::post('register', [AuthController::class, 'register'])->name('register');
            Route::post('login', [AuthController::class, 'login'])->name('login');
            Route::post('two-factor', [AuthController::class, 'confirmTwoFactor'])->name('two-factor');
            Route::post('password/reset', [PasswordResetController::class, 'reset'])->name('password.reset');
        });

        Route::post('password/forgot', [PasswordResetController::class, 'forgot'])
            ->middleware('throttle:otp')
            ->name('password.forgot');

        Route::middleware(['auth:sanctum', 'active'])->group(function () {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');

            Route::post('phone/send-code', [PhoneVerificationController::class, 'send'])
                ->middleware('throttle:otp')
                ->name('phone.send-code');
            Route::post('phone/verify', [PhoneVerificationController::class, 'verify'])
                ->middleware('throttle:auth')
                ->name('phone.verify');

            Route::get('sessions', [DeviceSessionController::class, 'index'])->name('sessions.index');
            Route::delete('sessions/{tokenId}', [DeviceSessionController::class, 'destroy'])
                ->whereUuid('tokenId')
                ->name('sessions.destroy');
        });
    });

    // Public: buyers can browse without signing in.
    Route::get('farms', [FarmController::class, 'index'])->name('farms.index');
    Route::get('farms/{farm}', [FarmController::class, 'show'])->name('farms.show');
    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::get('units', [UnitController::class, 'index'])->name('units.index');
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');
    Route::get('farms/{farm}/reviews', [ReviewController::class, 'forFarm'])->name('farms.reviews.index');
    Route::get('products/{product}/reviews', [ReviewController::class, 'forProduct'])->name('products.reviews.index');

    // Payment providers; authenticated by signature, not by user.
    Route::post('webhooks/payments/{provider}', PaymentWebhookController::class)->name('webhooks.payments');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('me', [MeController::class, 'show'])->name('me.show');
        Route::patch('me', [MeController::class, 'update'])->name('me.update');
        Route::post('me/roles', [MyRoleController::class, 'store'])->name('me.roles.store');
        Route::get('me/farms', [MyFarmController::class, 'index'])->name('me.farms.index');

        Route::get('me/notifications', [NotificationController::class, 'index'])->name('me.notifications.index');
        Route::post('me/notifications/read-all', [NotificationController::class, 'readAll'])->name('me.notifications.read-all');
        Route::post('me/notifications/{notification}/read', [NotificationController::class, 'read'])
            ->whereUuid('notification')
            ->name('me.notifications.read');
        Route::get('me/notification-preferences', [NotificationPreferenceController::class, 'show'])->name('me.notification-preferences.show');
        Route::patch('me/notification-preferences', [NotificationPreferenceController::class, 'update'])->name('me.notification-preferences.update');

        Route::get('me/favourites', [FavouriteController::class, 'index'])->name('me.favourites.index');
        Route::put('me/favourites/{product}', [FavouriteController::class, 'store'])->name('me.favourites.store');
        Route::delete('me/favourites/{product}', [FavouriteController::class, 'destroy'])->name('me.favourites.destroy');

        Route::get('me/addresses', [AddressController::class, 'index'])->name('me.addresses.index');
        Route::post('me/addresses', [AddressController::class, 'store'])->name('me.addresses.store');
        Route::patch('me/addresses/{address}', [AddressController::class, 'update'])->name('me.addresses.update');
        Route::delete('me/addresses/{address}', [AddressController::class, 'destroy'])->name('me.addresses.destroy');

        // Buying
        Route::get('cart', [CartController::class, 'show'])->name('cart.show');
        Route::delete('cart', [CartController::class, 'clear'])->name('cart.clear');
        Route::post('cart/items', [CartItemController::class, 'store'])->name('cart.items.store');
        Route::patch('cart/items/{item}', [CartItemController::class, 'update'])->name('cart.items.update');
        Route::delete('cart/items/{item}', [CartItemController::class, 'destroy'])->name('cart.items.destroy');
        Route::post('checkout/quote', [CheckoutController::class, 'quote'])->name('checkout.quote');

        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::post('orders', [OrderController::class, 'store'])->middleware('verified.phone')->name('orders.store');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');

        Route::post('payments/initiate', [PaymentController::class, 'initiate'])
            ->middleware(['verified.phone', 'throttle:payments'])
            ->name('payments.initiate');
        Route::get('payments/{payment}/status', [PaymentController::class, 'status'])
            ->middleware('throttle:payment-status')
            ->name('payments.status');

        // Selling: a farm's part of an order (the buyer can view it and confirm receipt)
        Route::get('farms/{farm}/orders', [FarmOrderController::class, 'index'])->name('farms.orders.index');
        Route::get('farms/{farm}/earnings', [FarmEarningsController::class, 'show'])->name('farms.earnings.show');
        Route::get('farms/{farm}/analytics', [FarmAnalyticsController::class, 'show'])->name('farms.analytics.show');
        Route::get('farms/{farm}/payouts', [FarmPayoutController::class, 'index'])->name('farms.payouts.index');
        Route::get('farms/{farm}/payout-account', [FarmPayoutController::class, 'showAccount'])->name('farms.payout-account.show');
        Route::put('farms/{farm}/payout-account', [FarmPayoutController::class, 'updateAccount'])
            ->middleware('verified.phone')
            ->name('farms.payout-account.update');
        Route::get('seller-orders/{sellerOrder}', [SellerOrderController::class, 'show'])->name('seller-orders.show');
        Route::post('seller-orders/{sellerOrder}/accept', [SellerOrderController::class, 'accept'])->name('seller-orders.accept');
        Route::post('seller-orders/{sellerOrder}/reject', [SellerOrderController::class, 'reject'])->name('seller-orders.reject');
        Route::post('seller-orders/{sellerOrder}/ready', [SellerOrderController::class, 'ready'])->name('seller-orders.ready');
        Route::post('seller-orders/{sellerOrder}/dispatch', [SellerOrderController::class, 'dispatch'])->name('seller-orders.dispatch');
        Route::post('seller-orders/{sellerOrder}/handover', [SellerOrderController::class, 'handover'])
            ->middleware('throttle:handover')
            ->name('seller-orders.handover');
        Route::post('seller-orders/{sellerOrder}/confirm-receipt', [SellerOrderController::class, 'confirmReceipt'])->name('seller-orders.confirm-receipt');

        // Messages between buyers and farms
        Route::get('conversations', [ConversationController::class, 'index'])->name('conversations.index');
        Route::post('conversations', [ConversationController::class, 'store'])->middleware('throttle:messages')->name('conversations.store');
        Route::get('conversations/{conversation}/messages', [ConversationController::class, 'messages'])->name('conversations.messages.index');
        Route::post('conversations/{conversation}/messages', [ConversationController::class, 'reply'])->middleware('throttle:messages')->name('conversations.messages.store');
        Route::post('conversations/{conversation}/read', [ConversationController::class, 'read'])->name('conversations.read');

        // Problems with delivered orders: the buyer reports, both sides talk, support decides
        Route::post('seller-orders/{sellerOrder}/dispute', [DisputeController::class, 'store'])->name('seller-orders.dispute.store');
        Route::get('disputes', [DisputeController::class, 'index'])->name('disputes.index');
        Route::get('disputes/{dispute}', [DisputeController::class, 'show'])->name('disputes.show');
        Route::post('disputes/{dispute}/messages', [DisputeController::class, 'message'])->middleware('throttle:messages')->name('disputes.messages.store');
        Route::post('disputes/{dispute}/evidence', [DisputeController::class, 'uploadEvidence'])->name('disputes.evidence.store');
        Route::get('disputes/{dispute}/evidence/{evidence}', [DisputeController::class, 'showEvidence'])
            ->scopeBindings()
            ->name('disputes.evidence.show');
        Route::post('disputes/{dispute}/withdraw', [DisputeController::class, 'withdraw'])->name('disputes.withdraw');

        // Reviews: the buyer of a completed farm order; the farm replies once
        Route::post('seller-orders/{sellerOrder}/review', [ReviewController::class, 'store'])->name('seller-orders.review.store');
        Route::post('reviews/{review}/response', [ReviewController::class, 'respond'])->name('reviews.response.store');

        Route::post('farms', [FarmController::class, 'store'])
            ->middleware(['role:farmer', 'verified.phone'])
            ->name('farms.store');
        Route::patch('farms/{farm}', [FarmController::class, 'update'])->name('farms.update');
        Route::get('farms/{farm}/products', [FarmProductController::class, 'index'])->name('farms.products.index');

        Route::scopeBindings()->group(function () {
            Route::post('farms/{farm}/locations', [FarmLocationController::class, 'store'])->name('farms.locations.store');
            Route::patch('farms/{farm}/locations/{location}', [FarmLocationController::class, 'update'])->name('farms.locations.update');
            Route::delete('farms/{farm}/locations/{location}', [FarmLocationController::class, 'destroy'])->name('farms.locations.destroy');
        });

        Route::get('farms/{farm}/verification', [FarmVerificationController::class, 'show'])->name('farms.verification.show');
        Route::post('farms/{farm}/verification', [FarmVerificationController::class, 'store'])
            ->middleware('verified.phone')
            ->name('farms.verification.store');

        // Listings (farm owners and managers)
        Route::post('products', [ProductController::class, 'store'])->name('products.store');
        Route::patch('products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
        Route::post('products/{product}/publish', [ProductLifecycleController::class, 'publish'])->name('products.publish');
        Route::post('products/{product}/pause', [ProductLifecycleController::class, 'pause'])->name('products.pause');
        Route::post('products/{product}/resume', [ProductLifecycleController::class, 'resume'])->name('products.resume');
        Route::post('products/{product}/renew', [ProductLifecycleController::class, 'renew'])->name('products.renew');

        Route::scopeBindings()->group(function () {
            Route::post('products/{product}/variants', [ProductVariantController::class, 'store'])->name('products.variants.store');
            Route::patch('products/{product}/variants/{variant}', [ProductVariantController::class, 'update'])->name('products.variants.update');
            Route::delete('products/{product}/variants/{variant}', [ProductVariantController::class, 'destroy'])->name('products.variants.destroy');

            Route::post('products/{product}/images', [ProductImageController::class, 'store'])->name('products.images.store');
            Route::put('products/{product}/images/order', [ProductImageController::class, 'reorder'])->name('products.images.reorder');
            Route::delete('products/{product}/images/{image}', [ProductImageController::class, 'destroy'])->name('products.images.destroy');
        });

        // Stock
        Route::get('products/{product}/batches', [InventoryBatchController::class, 'index'])->name('products.batches.index');
        Route::post('products/{product}/batches', [InventoryBatchController::class, 'store'])->name('products.batches.store');
        Route::get('products/{product}/stock-movements', [StockMovementController::class, 'index'])->name('products.stock-movements.index');
        Route::post('inventory/adjustments', [InventoryAdjustmentController::class, 'store'])->name('inventory.adjustments.store');

        // Support staff work the dispute queue alongside administrators.
        Route::prefix('admin')->name('admin.')->middleware('role:admin,super_admin,support_agent')->group(function () {
            Route::get('disputes', [AdminDisputeController::class, 'index'])->name('disputes.index');
            Route::post('disputes/{dispute}/resolve', [AdminDisputeController::class, 'resolve'])->name('disputes.resolve');
            Route::get('orders', [AdminOrderController::class, 'index'])->name('orders.index');
            Route::get('orders/{order}', [AdminOrderController::class, 'show'])->name('orders.show');
            Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
            Route::get('users/{user}', [AdminUserController::class, 'show'])->name('users.show');
        });

        // Roles and the audit trail are for super administrators only.
        Route::prefix('admin')->name('admin.')->middleware('role:super_admin')->group(function () {
            Route::post('users/{user}/roles', [AdminUserController::class, 'grantRole'])->name('users.roles.store');
            Route::delete('users/{user}/roles/{role}', [AdminUserController::class, 'revokeRole'])->name('users.roles.destroy');
            Route::get('audit-logs', [AdminAuditLogController::class, 'index'])->name('audit-logs.index');
        });

        Route::prefix('admin')->name('admin.')->middleware('role:admin,super_admin')->group(function () {
            Route::get('dashboard', [AdminDashboardController::class, 'show'])->name('dashboard');
            Route::post('users/{user}/suspend', [AdminUserController::class, 'suspend'])->name('users.suspend');
            Route::post('users/{user}/reactivate', [AdminUserController::class, 'reactivate'])->name('users.reactivate');

            Route::get('payments', [AdminPaymentController::class, 'index'])->name('payments.index');
            Route::post('payments/{payment}/refund', [AdminPaymentController::class, 'refund'])->name('payments.refund');
            Route::get('refunds', [AdminRefundController::class, 'index'])->name('refunds.index');
            Route::get('payouts', [AdminPayoutController::class, 'index'])->name('payouts.index');
            Route::get('payouts/due', [AdminPayoutController::class, 'due'])->name('payouts.due');
            Route::post('farms/{farm}/payouts', [AdminPayoutController::class, 'store'])->name('farms.payouts.store');
            Route::post('refunds/{refund}/retry', [AdminRefundController::class, 'retry'])->name('refunds.retry');
            Route::post('refunds/{refund}/complete', [AdminRefundController::class, 'complete'])->name('refunds.complete');

            Route::get('farms', [AdminFarmController::class, 'index'])->name('farms.index');
            Route::get('farm-verifications', [AdminFarmVerificationController::class, 'index'])->name('farm-verifications.index');
            Route::get('farm-verifications/{verification}', [AdminFarmVerificationController::class, 'show'])->name('farm-verifications.show');
            Route::post('farm-verifications/{verification}/approve', [AdminFarmVerificationController::class, 'approve'])->name('farm-verifications.approve');
            Route::post('farm-verifications/{verification}/reject', [AdminFarmVerificationController::class, 'reject'])->name('farm-verifications.reject');
            Route::get('farm-verifications/{verification}/documents/{document}', [AdminFarmVerificationDocumentController::class, 'show'])
                ->scopeBindings()
                ->name('farm-verifications.documents.show');

            Route::get('products', [AdminProductModerationController::class, 'index'])->name('products.index');
            Route::post('products/{product}/approve', [AdminProductModerationController::class, 'approve'])->name('products.approve');
            Route::post('products/{product}/reject', [AdminProductModerationController::class, 'reject'])->name('products.reject');

            Route::get('categories', [AdminCategoryController::class, 'index'])->name('categories.index');
            Route::post('categories', [AdminCategoryController::class, 'store'])->name('categories.store');
            Route::patch('categories/{category}', [AdminCategoryController::class, 'update'])->name('categories.update');

            Route::get('reviews', [AdminReviewController::class, 'index'])->name('reviews.index');
            Route::post('reviews/{review}/hide', [AdminReviewController::class, 'hide'])->name('reviews.hide');
            Route::post('reviews/{review}/unhide', [AdminReviewController::class, 'unhide'])->name('reviews.unhide');
        });
    });
});
