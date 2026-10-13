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
        // A problem the buyer reported with a delivered farm order, decided by support.
        Schema::create('disputes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('seller_order_id')->unique()->constrained();
            $table->foreignUuid('order_id')->index()->constrained();
            $table->foreignUuid('farm_id')->index()->constrained();
            $table->foreignUuid('buyer_id')->index()->constrained('users');
            $table->string('reason', 30);
            $table->text('description');
            $table->bigInteger('requested_refund_minor')->nullable();
            $table->string('status', 20)->index();
            $table->string('resolution', 20)->nullable();
            $table->bigInteger('refund_minor')->nullable();
            $table->text('resolution_note')->nullable();
            $table->foreignUuid('resolved_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
        });

        Schema::create('dispute_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dispute_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('author_id')->constrained('users');
            $table->string('author_party', 10);
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['dispute_id', 'created_at']);
        });

        // Photos, re-encoded (which drops GPS and other metadata) and kept off the public disk.
        Schema::create('dispute_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dispute_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignUuid('uploaded_by')->constrained('users');
            $table->string('uploader_party', 10);
            $table->string('disk', 30);
            $table->string('path');
            $table->string('mime_type', 50);
            $table->unsignedInteger('size_bytes');
            $table->timestamp('created_at')->useCurrent();
        });

        // Money given back to a payer: after a dispute, or for a payment the order could not take.
        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->index()->constrained();
            $table->foreignUuid('order_id')->index()->constrained();
            $table->foreignUuid('seller_order_id')->nullable()->index()->constrained();
            $table->foreignUuid('dispute_id')->nullable()->index()->constrained();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 20)->index();
            $table->string('provider_reference', 100)->nullable();
            $table->string('failure_reason', 200)->nullable();
            $table->foreignUuid('requested_by')->nullable()->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table) {
            // Committed refunds (sent or still owed). Never more than the amount paid.
            $table->bigInteger('refunded_minor')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('refunded_minor');
        });

        Schema::dropIfExists('refunds');
        Schema::dropIfExists('dispute_evidence');
        Schema::dropIfExists('dispute_messages');
        Schema::dropIfExists('disputes');
    }
};
