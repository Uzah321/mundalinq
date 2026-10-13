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
        Schema::create('units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('name', 50);
            $table->string('plural_name', 50);
            $table->string('measure', 20); // mass, volume or count
            // Size of one unit in its measure's base unit (kg for mass, litre for volume); null for count units.
            $table->decimal('base_factor', 14, 6)->nullable();
            $table->boolean('allows_fraction')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Units are reference data the code relies on (conversions, whole-number rules), so they ship with the schema.
        $units = [
            ['kg', 'kilogram', 'kilograms', 'mass', '1', true],
            ['g', 'gram', 'grams', 'mass', '0.001', false],
            ['tonne', 'tonne', 'tonnes', 'mass', '1000', true],
            ['litre', 'litre', 'litres', 'volume', '1', true],
            ['head', 'head', 'heads', 'count', null, false],
            ['bunch', 'bunch', 'bunches', 'count', null, false],
            ['piece', 'piece', 'pieces', 'count', null, false],
            ['bag', 'bag', 'bags', 'count', null, false],
            ['sack', 'sack', 'sacks', 'count', null, false],
            ['crate', 'crate', 'crates', 'count', null, false],
            ['tray', 'tray', 'trays', 'count', null, false],
            ['animal', 'animal', 'animals', 'count', null, false],
        ];

        DB::table('units')->insert(array_map(fn (array $unit, int $position) => [
            'id' => (string) Str::uuid7(),
            'code' => $unit[0],
            'name' => $unit[1],
            'plural_name' => $unit[2],
            'measure' => $unit[3],
            'base_factor' => $unit[4],
            'allows_fraction' => $unit[5],
            'sort_order' => $position,
            'created_at' => now(),
            'updated_at' => now(),
        ], $units, array_keys($units)));

        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('name', 80);
            $table->string('slug', 100)->unique();
            $table->string('description')->nullable();
            // Listings here are always reviewed by an admin first (e.g. livestock, dairy).
            $table->boolean('requires_approval')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Self-reference added once the table (and its primary key) exists.
        Schema::table('categories', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('categories')->restrictOnDelete();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->index()->constrained()->restrictOnDelete();
            $table->foreignUuid('category_id')->index()->constrained()->restrictOnDelete();
            $table->foreignUuid('farm_location_id')->index()->constrained()->restrictOnDelete();
            // Stock is always counted in this unit; selling options convert to it.
            $table->foreignUuid('stock_unit_id')->index()->constrained('units')->restrictOnDelete();
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->string('quality_grade', 1)->nullable();
            $table->char('currency', 3);
            $table->boolean('offers_pickup')->default(true);
            $table->boolean('offers_delivery')->default(false);
            $table->decimal('stock_on_hand', 14, 3)->default(0);
            $table->decimal('stock_reserved', 14, 3)->default(0);
            $table->decimal('low_stock_threshold', 14, 3)->nullable();
            $table->date('last_harvested_on')->nullable();
            $table->string('status', 20)->default('draft');
            $table->text('rejection_reason')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'expires_at']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignUuid('unit_id')->index()->constrained()->restrictOnDelete();
            $table->string('label', 60)->nullable(); // e.g. "Crate (about 28 kg)"
            // How much of the product's stock unit one of these sale units uses (a 28 kg crate = 28).
            $table->decimal('base_quantity', 14, 3);
            $table->bigInteger('price_minor'); // price per sale unit in cents
            $table->decimal('min_order_quantity', 14, 3)->default(1);
            $table->decimal('max_order_quantity', 14, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('variant_price_tiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_variant_id')->constrained()->cascadeOnDelete();
            $table->decimal('min_quantity', 14, 3);
            $table->bigInteger('price_minor');
            $table->timestamps();
            $table->unique(['product_variant_id', 'min_quantity']);
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->index()->constrained()->cascadeOnDelete();
            $table->string('disk', 30);
            $table->string('path');
            $table->string('thumb_path');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedInteger('size_bytes');
            $table->unsignedSmallInteger('position')->default(0); // 0 is the cover photo
            $table->timestamps();
        });

        DB::statement('ALTER TABLE products ADD CONSTRAINT products_stock_non_negative CHECK (stock_on_hand >= 0 AND stock_reserved >= 0)');
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_positive_values CHECK (base_quantity > 0 AND price_minor > 0 AND min_order_quantity > 0)');
        DB::statement('ALTER TABLE variant_price_tiers ADD CONSTRAINT variant_price_tiers_positive_values CHECK (min_quantity > 0 AND price_minor > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('variant_price_tiers');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('units');
    }
};
