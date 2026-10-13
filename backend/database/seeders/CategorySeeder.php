<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Starting category tree for the Zimbabwe pilot. Safe to run repeatedly in any environment:
 * it adds missing categories and never overwrites changes made by admins.
 */
class CategorySeeder extends Seeder
{
    private const TREE = [
        'Vegetables' => ['Tomatoes', 'Leafy greens', 'Cabbages', 'Onions', 'Potatoes', 'Butternut & pumpkins', 'Carrots', 'Peppers & chillies', 'Other vegetables'],
        'Fruit' => ['Citrus', 'Bananas', 'Avocados', 'Mangoes', 'Other fruit'],
        'Grains & cereals' => ['Maize', 'Sorghum & millet', 'Wheat', 'Other grains'],
        'Legumes & nuts' => ['Sugar beans', 'Groundnuts', 'Soya beans', 'Cowpeas & round nuts'],
        'Livestock' => ['Cattle', 'Goats & sheep', 'Pigs', 'Live poultry'],
        'Eggs & dairy' => ['Eggs', 'Milk & dairy'],
        'Honey' => [],
        'Herbs & spices' => [],
    ];

    /** Food safety and animal movement rules mean these are always reviewed before listing. */
    private const REVIEWED = ['Livestock', 'Milk & dairy'];

    public function run(): void
    {
        $position = 0;

        foreach (self::TREE as $group => $children) {
            $parent = $this->ensure($group, null, $position++);

            foreach ($children as $childPosition => $child) {
                $this->ensure($child, $parent, $childPosition);
            }
        }
    }

    private function ensure(string $name, ?Category $parent, int $position): Category
    {
        return Category::firstOrCreate(['slug' => Str::slug(str_replace('&', 'and', $name))], [
            'name' => $name,
            'parent_id' => $parent?->id,
            'requires_approval' => in_array($name, self::REVIEWED, true),
            'sort_order' => $position,
        ]);
    }
}
