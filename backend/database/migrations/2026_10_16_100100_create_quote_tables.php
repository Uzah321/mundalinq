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
        // A buyer asking farms for a price, e.g. 200 kg of tomatoes every Friday.
        Schema::create('quote_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('buyer_id')->index()->constrained('users');
            $table->foreignUuid('category_id')->index()->constrained();
            $table->string('title', 120);
            $table->decimal('quantity', 14, 3);
            $table->foreignUuid('unit_id')->constrained('units');
            // one_off, weekly, fortnightly or monthly
            $table->string('frequency', 20);
            $table->date('needed_by');
            // pickup, delivery or either
            $table->string('fulfilment', 20);
            $table->string('province', 60);
            $table->string('district', 80);
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamp('closes_at');
            $table->timestamps();
            $table->index(['status', 'closes_at']);
        });

        // A farm's answer: a price on one of its listings. One per farm per request.
        Schema::create('quote_offers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('quote_request_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignUuid('farm_id')->index()->constrained();
            $table->foreignUuid('product_variant_id')->constrained();
            $table->bigInteger('unit_price_minor');
            $table->char('currency', 3);
            $table->decimal('quantity', 14, 3);
            $table->bigInteger('delivery_fee_minor')->default(0);
            $table->string('fulfilment', 20);
            $table->text('message')->nullable();
            // For repeat orders: the price holds until then.
            $table->date('valid_until')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['quote_request_id', 'farm_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            // Set when the order came from an accepted offer rather than the basket.
            $table->foreignUuid('quote_offer_id')->nullable()->index()->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quote_offer_id');
        });

        Schema::dropIfExists('quote_offers');
        Schema::dropIfExists('quote_requests');
    }
};
