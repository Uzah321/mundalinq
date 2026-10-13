<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 50)->unique();
            $table->string('name', 100);
            $table->timestamps();
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['user_id', 'role_id']);
            $table->index('role_id');
        });

        // Roles are reference data the application depends on, so they ship with the schema.
        $roles = [
            'super_admin' => 'Super administrator',
            'admin' => 'Marketplace administrator',
            'farmer' => 'Farmer / seller',
            'buyer' => 'Customer / buyer',
            'business_buyer' => 'Business buyer',
            'delivery_partner' => 'Delivery partner',
            'support_agent' => 'Support agent',
        ];

        DB::table('roles')->insert(array_map(fn ($slug, $name) => [
            'id' => (string) Str::uuid7(),
            'slug' => $slug,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ], array_keys($roles), $roles));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('roles');
    }
};
