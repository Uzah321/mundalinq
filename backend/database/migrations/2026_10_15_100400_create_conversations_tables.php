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
        // One thread per buyer and farm; every member of the farm can read and reply.
        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('buyer_id')->constrained('users');
            $table->foreignUuid('farm_id')->index()->constrained();
            // Microsecond precision throughout: read markers are compared with message times.
            $table->timestamp('last_message_at', 6)->nullable();
            // Read markers per side: messages after these are unread for that side.
            $table->timestamp('buyer_last_read_at', 6)->nullable();
            $table->timestamp('farm_last_read_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['buyer_id', 'farm_id']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sender_id')->index()->constrained('users');
            $table->string('sender_side', 10);
            $table->text('body');
            // What the message is about, so the app can link to it.
            $table->foreignUuid('product_id')->nullable()->constrained();
            $table->foreignUuid('seller_order_id')->nullable()->constrained();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->index(['conversation_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
