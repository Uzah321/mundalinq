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
        // A verified-purchase review: one per completed farm order.
        Schema::create('reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('seller_order_id')->unique()->constrained();
            $table->foreignUuid('farm_id')->constrained();
            $table->foreignUuid('buyer_id')->index()->constrained('users');
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->text('farm_response')->nullable();
            $table->timestamp('farm_responded_at')->nullable();
            // Moderation: hidden reviews leave public listings and ratings but are kept.
            $table->timestamp('hidden_at')->nullable();
            $table->string('hidden_reason', 300)->nullable();
            $table->foreignUuid('hidden_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['farm_id', 'hidden_at']);
        });

        // The listings an order's review covers, so each product page can show it.
        Schema::create('review_products', function (Blueprint $table) {
            $table->foreignUuid('review_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->index()->constrained();
            $table->primary(['review_id', 'product_id']);
        });

        // Kept up to date by ReviewService so cards and search never aggregate on the fly.
        Schema::table('farms', function (Blueprint $table) {
            $table->decimal('rating_average', 3, 2)->nullable();
            $table->unsignedInteger('rating_count')->default(0);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('rating_average', 3, 2)->nullable();
            $table->unsignedInteger('rating_count')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['rating_average', 'rating_count']);
        });

        Schema::table('farms', function (Blueprint $table) {
            $table->dropColumn(['rating_average', 'rating_count']);
        });

        Schema::dropIfExists('review_products');
        Schema::dropIfExists('reviews');
    }
};
