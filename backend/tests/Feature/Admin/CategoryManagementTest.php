<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleSlug;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithCatalog;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use InteractsWithCatalog, RefreshDatabase;

    public function test_public_tree_shows_active_categories_only(): void
    {
        $vegetables = Category::factory()->create(['name' => 'Vegetables']);
        Category::factory()->childOf($vegetables)->create(['name' => 'Tomatoes']);
        Category::factory()->childOf($vegetables)->create(['name' => 'Retired', 'is_active' => false]);
        Category::factory()->create(['name' => 'Hidden group', 'is_active' => false]);

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Vegetables')
            ->assertJsonCount(1, 'data.0.children')
            ->assertJsonPath('data.0.children.0.name', 'Tomatoes');
    }

    public function test_units_are_public(): void
    {
        $this->getJson('/api/v1/units')
            ->assertOk()
            ->assertJsonFragment(['code' => 'crate', 'measure' => 'count', 'allows_fraction' => false]);
    }

    public function test_admin_creates_subcategories_with_unique_slugs(): void
    {
        $vegetables = Category::factory()->create();
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/categories', ['name' => 'Mushrooms', 'parent_id' => $vegetables->id])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'mushrooms')
            ->assertJsonPath('data.parent_id', $vegetables->id);

        $this->postJson('/api/v1/admin/categories', ['name' => 'Mushrooms'])->assertJsonPath('data.slug', 'mushrooms-2');
    }

    public function test_categories_are_at_most_two_levels_deep(): void
    {
        $vegetables = Category::factory()->create();
        $tomatoes = Category::factory()->childOf($vegetables)->create();
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/categories', ['name' => 'Cherry', 'parent_id' => $tomatoes->id])
            ->assertJsonValidationErrors(['parent_id' => 'Choose a top-level category as the parent.']);

        $fruit = Category::factory()->create();
        $this->patchJson("/api/v1/admin/categories/{$vegetables->id}", ['parent_id' => $fruit->id])
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_subcategories_inherit_review_requirements(): void
    {
        $livestock = Category::factory()->requiringApproval()->create();
        $goats = Category::factory()->childOf($livestock)->create();

        $this->assertTrue($goats->requiresApproval());
        $this->assertFalse(Category::factory()->create()->requiresApproval());
    }

    public function test_only_admins_manage_categories(): void
    {
        Sanctum::actingAs(User::factory()->withRole(RoleSlug::Farmer)->create());

        $this->postJson('/api/v1/admin/categories', ['name' => 'Mine'])->assertForbidden();
    }
}
