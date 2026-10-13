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
        Schema::create('user_addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->index()->constrained()->cascadeOnDelete();
            $table->string('label', 50);
            $table->string('recipient_name', 120);
            $table->string('phone', 20);
            $table->string('province', 60);
            $table->string('district', 80);
            $table->string('ward', 80)->nullable();
            $table->string('address_line');
            // Landmarks matter where there are no street addresses.
            $table->string('directions', 500)->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // Farm-managed delivery: a flat fee per order, optionally free above an order value.
        Schema::table('farms', function (Blueprint $table) {
            $table->bigInteger('delivery_fee_minor')->default(0);
            $table->bigInteger('free_delivery_over_minor')->nullable();
        });

        Schema::create('carts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_variant_id')->index()->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->timestamps();
            $table->unique(['cart_id', 'product_variant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');

        Schema::table('farms', function (Blueprint $table) {
            $table->dropColumn(['delivery_fee_minor', 'free_delivery_over_minor']);
        });

        Schema::dropIfExists('user_addresses');
    }
};
