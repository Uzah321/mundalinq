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
        // Where a farm's earnings are sent. The number is encrypted; changes are audited and alerted.
        Schema::create('farm_payout_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('method', 30);
            $table->string('provider', 50);
            $table->string('account_name', 120);
            $table->text('account_number');
            $table->foreignUuid('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        // Money sent to a farm by the marketplace (recorded by an administrator after the transfer).
        Schema::create('payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->index()->constrained();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('method', 30);
            // Snapshot: the account may change later.
            $table->string('provider', 50);
            $table->string('account_name', 120);
            $table->string('account_number_last4', 4);
            $table->string('reference', 100);
            $table->string('note', 300)->nullable();
            $table->foreignUuid('paid_by')->constrained('users');
            $table->timestamp('paid_at');
            $table->timestamps();
        });

        Schema::table('settlement_ledger', function (Blueprint $table) {
            $table->foreignUuid('payout_id')->nullable()->index()->constrained();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settlement_ledger', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payout_id');
        });

        Schema::dropIfExists('payouts');
        Schema::dropIfExists('farm_payout_accounts');
    }
};
