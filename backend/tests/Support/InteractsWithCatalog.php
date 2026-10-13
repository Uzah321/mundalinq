<?php

namespace Tests\Support;

use App\Enums\RoleSlug;
use App\Models\Category;
use App\Models\Farm;
use App\Models\User;
use App\Support\Market;
use Laravel\Sanctum\Sanctum;

trait InteractsWithCatalog
{
    /**
     * @return array{0: User, 1: Farm}
     */
    protected function farmerWithFarm(array $farmAttributes = [], bool $approved = true): array
    {
        $farmer = User::factory()->withRole(RoleSlug::Farmer)->create();
        $factory = Farm::factory()->ownedBy($farmer);
        $farm = ($approved ? $factory->approved() : $factory)->create($farmAttributes);

        return [$farmer, $farm];
    }

    protected function actingAsAdmin(): User
    {
        Sanctum::actingAs($admin = User::factory()->withRole(RoleSlug::Admin)->create());

        return $admin;
    }

    protected function productPayload(Farm $farm, ?Category $category = null, array $overrides = []): array
    {
        return array_replace([
            'farm_id' => $farm->id,
            'category_id' => ($category ?? Category::factory()->create())->id,
            'title' => 'Roma tomatoes',
            'description' => 'Firm and red, picked this week.',
            'quality_grade' => 'A',
            'stock_unit' => 'kg',
            'variants' => [
                ['unit' => 'kg', 'price' => '1.20', 'min_order_quantity' => '5'],
                [
                    'unit' => 'crate',
                    'label' => 'Crate (about 28 kg)',
                    'base_quantity' => '28',
                    'price' => '30.00',
                    'price_tiers' => [['min_quantity' => '10', 'price' => '27.00']],
                ],
            ],
            'initial_stock' => ['quantity' => '500', 'harvested_on' => Market::today()],
        ], $overrides);
    }
}
