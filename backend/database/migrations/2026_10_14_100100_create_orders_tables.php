<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // What the buyer placed and pays for, once, across every farm in the cart.
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 20)->unique();
            $table->foreignUuid('buyer_id')->index()->constrained('users');
            $table->string('status', 30)->index();
            $table->char('currency', 3);
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('delivery_fee_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor');
            $table->string('note', 500)->nullable();
            $table->timestamp('payment_due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 30)->nullable();
            $table->timestamps();
        });

        // One farm's part of an order: confirmed, prepared and handed over by that farm.
        Schema::create('seller_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignUuid('farm_id')->index()->constrained();
            $table->string('number', 24)->unique();
            $table->string('status', 30)->index();
            $table->string('fulfilment_method', 20);
            // Snapshot: the buyer may later edit or delete the saved address.
            $table->jsonb('delivery_address')->nullable();
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('delivery_fee_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor');
            // Encrypted. The buyer shows it when receiving the goods: proof of handover.
            $table->text('handover_code');
            $table->string('rejection_reason', 300)->nullable();
            $table->timestamp('confirm_by');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'confirm_by']);
        });

        // Immutable snapshot of what was bought and at what price; later listing edits never change it.
        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('seller_order_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->index()->constrained();
            $table->foreignUuid('product_variant_id')->index()->constrained();
            $table->string('product_title', 120);
            $table->string('variant_label', 60)->nullable();
            $table->string('unit_code', 20);
            $table->string('unit_name', 50);
            $table->string('unit_plural_name', 50);
            $table->decimal('base_quantity', 14, 3);
            $table->decimal('quantity', 14, 3); // ordered, in sale units
            $table->decimal('confirmed_quantity', 14, 3)->nullable(); // what the farm agreed to supply
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('line_total_minor');
            $table->decimal('stock_quantity', 14, 3); // stock units held for (then taken by) this item
            $table->timestamps();
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignUuid('seller_order_id')->nullable()->index()->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignUuid('actor_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('note', 300)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('seller_orders');
        Schema::dropIfExists('orders');
    }
};
