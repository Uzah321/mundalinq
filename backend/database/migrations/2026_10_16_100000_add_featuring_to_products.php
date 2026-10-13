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
        Schema::table('products', function (Blueprint $table) {
            // Until then the listing shows first in the market, with a "Featured" badge.
            $table->timestamp('featured_until')->nullable()->index();
        });

        // Each time a listing is featured: by its farm (paid from earnings) or by staff (free).
        Schema::create('product_promotions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->index()->constrained();
            $table->foreignUuid('farm_id')->index()->constrained();
            $table->unsignedSmallInteger('weeks');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_promotions');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('featured_until');
        });
    }
};
