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
        // One attempt to collect an order's total. An order may need several (a declined prompt, a retry).
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->index()->constrained();
            $table->foreignUuid('buyer_id')->index()->constrained('users');
            $table->string('provider', 30);
            $table->string('method', 30);
            $table->string('status', 20)->index();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('payer_phone', 20)->nullable();
            $table->string('provider_reference', 100)->nullable();
            $table->jsonb('provider_data')->nullable();
            $table->string('failure_reason', 200)->nullable();
            // Money arrived for an order that could no longer take it (cancelled, already paid): give it back.
            $table->boolean('requires_refund')->default(false);
            $table->timestamp('expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_reference']);
        });

        // Everything providers told us (webhooks) or we learned (polls); also blocks replayed webhooks.
        Schema::create('payment_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('provider', 30);
            $table->string('event_id', 100)->nullable();
            $table->string('source', 20);
            $table->string('status', 20)->nullable();
            $table->jsonb('payload')->nullable();
            $table->boolean('signature_valid')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['provider', 'event_id']);
        });

        // What each farm has earned. Amounts are from the farm's point of view: sales add, fees subtract.
        Schema::create('settlement_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->index()->constrained();
            $table->foreignUuid('seller_order_id')->nullable()->index()->constrained();
            $table->foreignUuid('payment_id')->nullable()->index()->constrained();
            $table->string('type', 30);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('description', 200)->nullable();
            // Set when the sale is final (delivered and undisputed) and the money can be paid out.
            $table->timestamp('available_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // The sandbox provider's own records. Only the sandbox gateway reads or writes this table.
        Schema::create('sandbox_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 64)->unique();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('phone', 20);
            $table->string('status', 20);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sandbox_payments');
        Schema::dropIfExists('settlement_ledger');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');
    }
};
