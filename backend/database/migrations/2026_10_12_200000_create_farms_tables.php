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
        Schema::create('farms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('farmer_type', 30);
            $table->string('contact_phone', 20)->nullable();
            $table->string('contact_email')->nullable();
            $table->char('country_code', 2)->default('ZW');
            $table->boolean('offers_pickup')->default(true);
            $table->boolean('offers_delivery')->default(false);
            $table->decimal('delivery_radius_km', 6, 2)->nullable();
            $table->string('verification_status', 20)->default('unverified')->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('farm_members', function (Blueprint $table) {
            $table->foreignUuid('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->timestamps();
            $table->primary(['farm_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('farm_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->index()->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('province', 60);
            $table->string('district', 80);
            $table->string('ward', 80)->nullable();
            $table->string('address_line')->nullable();
            // Plain coordinates for now; move to a PostGIS geography column when radius search arrives.
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->index(['province', 'district']);
        });

        Schema::create('farm_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignUuid('submitted_by')->index()->constrained('users');
            $table->string('status', 20)->index();
            $table->string('id_document_type', 30);
            $table->text('id_number'); // encrypted
            $table->text('business_registration_number')->nullable(); // encrypted
            $table->text('notes')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->index()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('farm_verification_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_verification_id')->index()->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('disk', 30);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('actor_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('action', 100)->index();
            $table->string('subject_type', 100)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('farm_verification_documents');
        Schema::dropIfExists('farm_verifications');
        Schema::dropIfExists('farm_locations');
        Schema::dropIfExists('farm_members');
        Schema::dropIfExists('farms');
    }
};
