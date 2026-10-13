<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // A harvest or delivery of stock. Quantities are in the product's stock unit.
        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->date('harvested_on')->nullable();
            $table->date('best_before')->nullable();
            $table->decimal('quantity_received', 14, 3);
            $table->decimal('quantity_on_hand', 14, 3);
            $table->string('notes', 500)->nullable();
            $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['product_id', 'code']);
        });

        // Append-only stock ledger: every change to stock on hand, who made it and why.
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('inventory_batch_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('type', 30);
            $table->decimal('quantity', 14, 3); // positive adds stock, negative removes it
            $table->decimal('balance_after', 14, 3); // product stock on hand after this movement
            $table->string('reason', 30)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignUuid('actor_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->nullableUuidMorphs('reference'); // e.g. the order item a sale belongs to
            $table->timestamp('created_at')->useCurrent();
            $table->index(['product_id', 'created_at']);
        });

        DB::statement('ALTER TABLE inventory_batches ADD CONSTRAINT inventory_batches_quantities CHECK (quantity_received > 0 AND quantity_on_hand >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_batches');
    }
};
