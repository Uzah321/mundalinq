<?php

namespace Database\Seeders;

use App\Enums\FarmerType;
use App\Models\Category;
use App\Models\Farm;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Services\Catalog\ProductImageService;
use App\Support\Market;
use App\Support\Money;
use Database\Factories\ProductFactory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * Demo farms around Zimbabwe and their listings, covering every category, several units and package
 * sizes, bulk prices, a low-stock listing, one awaiting review and one draft. Photos come from
 * database/seeders/photos; listings without one show their category icon.
 *
 * Every demo farm belongs to farmer@mundalinq.test. Safe to run again
 * (php artisan db:seed --class=DemoListingSeeder): farms and listings that already exist are left
 * alone, apart from getting their photo if they have none.
 */
class DemoListingSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(ProductImageService $images): void
    {
        $farmer = User::where('email', 'farmer@mundalinq.test')->first();

        if (! $farmer) {
            $this->command?->warn('There is no demo farmer yet. Run the full database seeder first.');

            return;
        }

        foreach ($this->farms() as $name => $details) {
            $farm = $this->farm($farmer, $name, $details);

            foreach ($details['listings'] as $title => $listing) {
                $product = Product::where('farm_id', $farm->id)->where('title', $title)->first()
                    ?? $this->factoryFor($listing, $farm)->create(['farm_id' => $farm->id, 'title' => $title]);

                $photo = $listing['photo'] ?? null;

                if ($photo && $product->images()->doesntExist()) {
                    $images->add($product, new UploadedFile(database_path("seeders/photos/{$photo}"), $photo, test: true));
                }
            }
        }
    }

    private function farm(User $farmer, string $name, array $details): Farm
    {
        $farm = Farm::where('name', $name)->first();

        if ($farm) {
            return $farm;
        }

        $farm = Farm::factory()->approved()->ownedBy($farmer)->create(['name' => $name, ...$details['farm']]);
        $farm->primaryLocation->update($details['location']);

        return $farm;
    }

    /**
     * Each "sell" option is [unit, price, how much stock one holds, label, [[min quantity, price], ...]];
     * "harvested" is days ago.
     *
     * @param  array{category: string, unit?: string, sell: list<array>, stock?: string, harvested?: int, grade?: ?string, status?: string, low_stock?: string, description: string}  $listing
     */
    private function factoryFor(array $listing, Farm $farm): ProductFactory
    {
        $factory = Product::factory()->state([
            'category_id' => Category::where('slug', $listing['category'])->value('id'),
            'stock_unit_id' => Unit::idFor($listing['unit'] ?? 'kg'),
            'description' => $listing['description'],
            'quality_grade' => array_key_exists('grade', $listing) ? $listing['grade'] : 'A',
            'offers_delivery' => $farm->offers_delivery,
            'low_stock_threshold' => $listing['low_stock'] ?? null,
        ]);

        $factory = match ($listing['status'] ?? 'active') {
            'active' => $factory->active(),
            'pending' => $factory->pendingReview(),
            'draft' => $factory,
        };

        foreach ($listing['sell'] as $position => $option) {
            [$unit, $price, $base, $label, $tiers] = $option + [2 => '1', 3 => null, 4 => []];

            $factory = $factory->withVariant($unit, $price, $base, ['label' => $label, 'sort_order' => $position])
                ->afterCreating(function (Product $product) use ($position, $tiers) {
                    $variant = $product->variants()->where('sort_order', $position)->first();

                    foreach ($tiers as [$minQuantity, $tierPrice]) {
                        $variant->priceTiers()->create(['min_quantity' => $minQuantity, 'price_minor' => Money::toMinor($tierPrice)]);
                    }
                });
        }

        if (isset($listing['stock'])) {
            $harvestedOn = isset($listing['harvested'])
                ? now(Market::timezone())->subDays($listing['harvested'])->toDateString()
                : null;

            $factory = $factory->withStock($listing['stock'], $harvestedOn);
        }

        return $factory;
    }

    private function farms(): array
    {
        return [
            'Demo Green Acres' => [
                'farm' => [
                    'description' => 'Family market garden outside Ruwa growing vegetables, fruit and free-range eggs.',
                    'offers_delivery' => true,
                    'delivery_radius_km' => 50,
                    'delivery_fee_minor' => 200,
                    'free_delivery_over_minor' => 5000,
                ],
                // Ruwa, about 21 km from central Harare, so the demo buyer is inside the delivery area.
                'location' => ['province' => 'Mashonaland East', 'district' => 'Goromonzi', 'latitude' => -17.8897, 'longitude' => 31.2447],
                'listings' => [
                    'Roma tomatoes' => [
                        'category' => 'tomatoes',
                        'sell' => [['kg', '1.20'], ['crate', '30.00', '28', 'Crate (about 28 kg)']],
                        'stock' => '500',
                        'harvested' => 0,
                        'photo' => 'tomatoes.jpg',
                        'description' => 'Firm, deep-red Romas picked this morning. Good for cooking down into sauce and relish.',
                    ],
                    'Covo bunches' => [
                        'category' => 'leafy-greens',
                        'unit' => 'bunch',
                        'sell' => [['bunch', '0.50']],
                        'stock' => '120',
                        'harvested' => 0,
                        'description' => 'Tender covo leaves, cut and bunched on harvest day.',
                    ],
                    'Carrots' => [
                        'category' => 'carrots',
                        'sell' => [['kg', '1.00']],
                        'stock' => '200',
                        'harvested' => 0,
                        'photo' => 'carrots.jpg',
                        'description' => 'Sweet, crunchy carrots pulled with their tops on.',
                    ],
                    'Broccoli' => [
                        'category' => 'other-vegetables',
                        'unit' => 'head',
                        'sell' => [['head', '1.50']],
                        'stock' => '60',
                        'harvested' => 0,
                        'photo' => 'broccoli.jpg',
                        'description' => 'Tight, dark-green heads, cut the day they are sold.',
                    ],
                    'Red apples' => [
                        'category' => 'other-fruit',
                        'sell' => [['kg', '2.00']],
                        'stock' => '150',
                        'photo' => 'apples.jpg',
                        'description' => 'Crisp, juicy red apples for lunchboxes and baking.',
                    ],
                    'Free-range eggs (30 per tray)' => [
                        'category' => 'eggs',
                        'unit' => 'tray',
                        'sell' => [['tray', '4.50']],
                        'stock' => '40',
                        'photo' => 'eggs.jpg',
                        'description' => 'Brown eggs from hens that roam the farm all day.',
                    ],
                    'Fresh farm milk' => [
                        'category' => 'milk-and-dairy',
                        'unit' => 'litre',
                        'sell' => [['litre', '1.20']],
                        'stock' => '80',
                        'harvested' => 0,
                        'photo' => 'milk.jpg',
                        'description' => 'Pasteurised whole milk, bottled every morning.',
                    ],
                    'Butternut (draft)' => [
                        'category' => 'butternut-and-pumpkins',
                        'sell' => [['kg', '0.80']],
                        'status' => 'draft',
                        'description' => 'Not published yet.',
                    ],
                ],
            ],

            'Domboshava Market Garden' => [
                'farm' => [
                    'description' => 'Smallholder garden below the Domboshava hills, supplying fresh greens to northern Harare.',
                    'offers_delivery' => true,
                    'delivery_radius_km' => 30,
                    'delivery_fee_minor' => 150,
                    'free_delivery_over_minor' => 2500,
                ],
                'location' => ['province' => 'Mashonaland East', 'district' => 'Goromonzi', 'latitude' => -17.6236, 'longitude' => 31.1531],
                'listings' => [
                    'Rape bunches' => [
                        'category' => 'leafy-greens',
                        'unit' => 'bunch',
                        'sell' => [['bunch', '0.50']],
                        'stock' => '200',
                        'harvested' => 0,
                        'description' => 'Young rape leaves, cut this morning. Ready for the pot.',
                    ],
                    'Spinach' => [
                        'category' => 'leafy-greens',
                        'unit' => 'bunch',
                        'sell' => [['bunch', '0.60']],
                        'stock' => '80',
                        'harvested' => 1,
                        'description' => 'Broad-leaf spinach, washed and bunched.',
                    ],
                    'Cabbages' => [
                        'category' => 'cabbages',
                        'unit' => 'head',
                        'sell' => [['head', '0.80'], ['bag', '7.00', '10', 'Bag of 10 heads']],
                        'stock' => '150',
                        'harvested' => 2,
                        'description' => 'Firm drumhead cabbages, about 2 kg each.',
                    ],
                    'Red onions' => [
                        'category' => 'onions',
                        'sell' => [['kg', '1.20'], ['bag', '10.00', '10', 'Pocket (10 kg)']],
                        'stock' => '300',
                        'harvested' => 6,
                        'grade' => 'B',
                        'description' => 'Cured red onions. Some are small, priced to match.',
                    ],
                    'Green peppers' => [
                        'category' => 'peppers-and-chillies',
                        'sell' => [['kg', '2.50']],
                        'stock' => '40',
                        'harvested' => 1,
                        'description' => 'Glossy green bell peppers, thick-walled and crunchy.',
                    ],
                    'Butternut squash' => [
                        'category' => 'butternut-and-pumpkins',
                        'sell' => [['kg', '0.80']],
                        'stock' => '250',
                        'harvested' => 5,
                        'description' => 'Sweet butternuts, 1 to 2 kg each. They keep for weeks.',
                    ],
                    'Sweet potatoes' => [
                        'category' => 'other-vegetables',
                        'sell' => [['kg', '1.00']],
                        'stock' => '180',
                        'harvested' => 4,
                        'description' => 'Orange-fleshed sweet potatoes, good roasted or boiled.',
                    ],
                ],
            ],

            'Mazowe Valley Orchards' => [
                'farm' => [
                    'description' => 'Citrus and fruit orchards in the Mazowe valley.',
                    'farmer_type' => FarmerType::Commercial,
                    'offers_delivery' => true,
                    'delivery_radius_km' => 60,
                    'delivery_fee_minor' => 300,
                    'free_delivery_over_minor' => 4000,
                ],
                'location' => ['province' => 'Mashonaland Central', 'district' => 'Mazowe', 'latitude' => -17.5072, 'longitude' => 30.9739],
                'listings' => [
                    'Mazowe oranges' => [
                        'category' => 'citrus',
                        'sell' => [['kg', '1.00', '1', null, [['50', '0.85']]], ['bag', '12.00', '15', 'Pocket (15 kg)']],
                        'stock' => '600',
                        'harvested' => 3,
                        'description' => 'Juicy Valencia oranges. 85c a kilogram when you buy 50 kg or more.',
                    ],
                    'Naartjies' => [
                        'category' => 'citrus',
                        'sell' => [['kg', '1.50']],
                        'stock' => '200',
                        'harvested' => 2,
                        'description' => 'Easy-peel naartjies, sweet and seedless.',
                    ],
                    'Lemons' => [
                        'category' => 'citrus',
                        'sell' => [['kg', '1.80']],
                        'stock' => '90',
                        'harvested' => 5,
                        'grade' => 'B',
                        'description' => 'Thick-skinned lemons with plenty of juice. Some marked skins.',
                    ],
                    'Avocados' => [
                        'category' => 'avocados',
                        'unit' => 'piece',
                        'sell' => [['piece', '0.40'], ['crate', '7.00', '20', 'Box of 20']],
                        'stock' => '400',
                        'harvested' => 4,
                        'description' => 'Hass avocados, picked firm. Ready to eat in 3 to 5 days.',
                    ],
                    'Bananas' => [
                        'category' => 'bananas',
                        'sell' => [['kg', '1.20']],
                        'stock' => '150',
                        'harvested' => 2,
                        'description' => 'Ripening bananas, sold by the kilogram.',
                    ],
                    'Early mangoes' => [
                        'category' => 'mangoes',
                        'unit' => 'piece',
                        'sell' => [['piece', '0.30']],
                        'stock' => '300',
                        'harvested' => 1,
                        'description' => 'The first mangoes of the season. Fragrant and sweet.',
                    ],
                ],
            ],

            'Marondera Livestock & Dairy' => [
                'farm' => [
                    'description' => 'Poultry, goats, pigs and a small dairy herd near Marondera.',
                    'offers_delivery' => true,
                    'delivery_radius_km' => 80,
                    'delivery_fee_minor' => 400,
                ],
                'location' => ['province' => 'Mashonaland East', 'district' => 'Marondera', 'latitude' => -18.1853, 'longitude' => 31.5519],
                'listings' => [
                    'Road-runner chickens (live)' => [
                        'category' => 'live-poultry',
                        'unit' => 'animal',
                        'sell' => [['animal', '8.00']],
                        'stock' => '40',
                        'grade' => null,
                        'description' => 'Free-range indigenous chickens, about 2 kg live weight.',
                    ],
                    'Broiler chickens (live)' => [
                        'category' => 'live-poultry',
                        'unit' => 'animal',
                        'sell' => [['animal', '6.50']],
                        'stock' => '4',
                        'low_stock' => '5',
                        'grade' => null,
                        'description' => 'Six-week broilers, about 2.2 kg live weight. Last few of this batch.',
                    ],
                    'Boer goats' => [
                        'category' => 'goats-and-sheep',
                        'unit' => 'animal',
                        'sell' => [['animal', '75.00']],
                        'stock' => '6',
                        'grade' => null,
                        'description' => 'Healthy Boer goats, 8 to 10 months old, dipped and dewormed.',
                    ],
                    'Weaner piglets' => [
                        'category' => 'pigs',
                        'unit' => 'animal',
                        'sell' => [['animal', '45.00']],
                        'stock' => '10',
                        'grade' => null,
                        'description' => 'Large White cross weaners, 8 weeks old.',
                    ],
                    'Brahman steer' => [
                        'category' => 'cattle',
                        'unit' => 'animal',
                        'sell' => [['animal', '650.00']],
                        'stock' => '2',
                        'grade' => null,
                        'status' => 'pending',
                        'description' => 'Two-year-old Brahman steer, about 400 kg. Waiting for admin review.',
                    ],
                    'Amasi (sour milk)' => [
                        'category' => 'milk-and-dairy',
                        'unit' => 'litre',
                        'sell' => [['litre', '1.50'], ['piece', '2.80', '2', '2-litre bottle']],
                        'stock' => '60',
                        'harvested' => 0,
                        'description' => 'Thick, naturally soured milk from our Jersey cows.',
                    ],
                ],
            ],

            'Chinhoyi Grain Co-op' => [
                'farm' => [
                    'description' => 'A co-operative of grain and legume growers. Collection from the depot only.',
                    'farmer_type' => FarmerType::Cooperative,
                ],
                'location' => ['province' => 'Mashonaland West', 'district' => 'Makonde', 'latitude' => -17.3667, 'longitude' => 30.2000],
                'listings' => [
                    'White maize' => [
                        'category' => 'maize',
                        'sell' => [['kg', '0.40'], ['bag', '18.00', '50', '50 kg bag', [['10', '17.00']]]],
                        'stock' => '5000',
                        'harvested' => 14,
                        'description' => 'Clean, dry white maize for mealie-meal. $17 a bag when you take 10 or more.',
                    ],
                    'Sugar beans' => [
                        'category' => 'sugar-beans',
                        'sell' => [['kg', '2.20'], ['bag', '50.00', '25', '25 kg bag']],
                        'stock' => '800',
                        'harvested' => 30,
                        'description' => 'Speckled sugar beans, sorted and bagged.',
                    ],
                    'Groundnuts (shelled)' => [
                        'category' => 'groundnuts',
                        'sell' => [['kg', '2.50']],
                        'stock' => '400',
                        'harvested' => 25,
                        'description' => 'Shelled groundnuts for peanut butter or roasting.',
                    ],
                    'Soya beans' => [
                        'category' => 'soya-beans',
                        'sell' => [['kg', '0.80'], ['bag', '38.00', '50', '50 kg bag', [['20', '36.00']]]],
                        'stock' => '3000',
                        'harvested' => 40,
                        'description' => 'Dry soya beans for feed or processing. $36 a bag for 20 bags or more.',
                    ],
                    'Red sorghum' => [
                        'category' => 'sorghum-and-millet',
                        'sell' => [['kg', '0.70']],
                        'stock' => '1000',
                        'harvested' => 35,
                        'description' => 'Red sorghum for brewing and porridge.',
                    ],
                    'Rapoko (finger millet)' => [
                        'category' => 'sorghum-and-millet',
                        'sell' => [['kg', '1.20']],
                        'stock' => '300',
                        'harvested' => 35,
                        'description' => 'Cleaned rapoko grain for sadza and porridge.',
                    ],
                    'Nyimo (round nuts)' => [
                        'category' => 'cowpeas-and-round-nuts',
                        'sell' => [['kg', '3.00']],
                        'stock' => '150',
                        'harvested' => 28,
                        'description' => 'Bambara round nuts, dried in the shell.',
                    ],
                ],
            ],

            'Nyanga Highlands Farm' => [
                'farm' => [
                    'description' => 'Cool-climate produce from the Eastern Highlands. Collection from the farm gate.',
                ],
                'location' => ['province' => 'Manicaland', 'district' => 'Nyanga', 'latitude' => -18.2167, 'longitude' => 32.7500],
                'listings' => [
                    'Potatoes' => [
                        'category' => 'potatoes',
                        'sell' => [['kg', '0.90'], ['bag', '12.00', '15', 'Pocket (15 kg)']],
                        'stock' => '2000',
                        'harvested' => 3,
                        'description' => 'Mondial potatoes from the highlands. Good for chips and mash.',
                    ],
                    'Garden peas' => [
                        'category' => 'other-vegetables',
                        'sell' => [['kg', '3.00']],
                        'stock' => '60',
                        'harvested' => 1,
                        'description' => 'Sweet peas in the pod, picked young.',
                    ],
                    'Strawberries' => [
                        'category' => 'other-fruit',
                        'sell' => [['piece', '3.50', '0.5', 'Punnet (500 g)']],
                        'stock' => '40',
                        'harvested' => 0,
                        'description' => 'Ripe strawberries, picked and packed this morning.',
                    ],
                    'Plums' => [
                        'category' => 'other-fruit',
                        'sell' => [['kg', '2.20']],
                        'stock' => '100',
                        'harvested' => 2,
                        'description' => 'Red-fleshed plums, sweet with a tart skin.',
                    ],
                ],
            ],

            'Gweru Honey & Herbs' => [
                'farm' => [
                    'description' => 'Beekeepers and herb growers outside Gweru.',
                    'offers_delivery' => true,
                    'delivery_radius_km' => 25,
                    'delivery_fee_minor' => 100,
                ],
                'location' => ['province' => 'Midlands', 'district' => 'Gweru', 'latitude' => -19.4500, 'longitude' => 29.8167],
                'listings' => [
                    'Raw honey' => [
                        'category' => 'honey',
                        'sell' => [['piece', '4.00', '0.5', '500 g jar'], ['piece', '7.50', '1', '1 kg jar']],
                        'stock' => '120',
                        'description' => 'Unheated wildflower honey, strained and jarred on the farm.',
                    ],
                    'Honeycomb' => [
                        'category' => 'honey',
                        'sell' => [['piece', '3.00', '0.25', '250 g comb']],
                        'stock' => '15',
                        'description' => 'Whole comb honey, cut from the frame.',
                    ],
                    'Fresh basil' => [
                        'category' => 'herbs-and-spices',
                        'unit' => 'bunch',
                        'sell' => [['bunch', '0.80']],
                        'stock' => '50',
                        'harvested' => 0,
                        'description' => 'Sweet basil, cut to order.',
                    ],
                    'Rosemary' => [
                        'category' => 'herbs-and-spices',
                        'unit' => 'bunch',
                        'sell' => [['bunch', '0.80']],
                        'stock' => '40',
                        'harvested' => 1,
                        'description' => 'Woody rosemary sprigs for roasting and teas.',
                    ],
                    'Moringa powder' => [
                        'category' => 'herbs-and-spices',
                        'sell' => [['piece', '5.00', '0.25', '250 g pouch']],
                        'stock' => '20',
                        'grade' => null,
                        'description' => 'Shade-dried moringa leaf, finely ground.',
                    ],
                    'Bird\'s eye chillies' => [
                        'category' => 'peppers-and-chillies',
                        'sell' => [['kg', '6.00'], ['piece', '0.80', '0.1', '100 g pack']],
                        'stock' => '25',
                        'harvested' => 2,
                        'description' => 'Small, fiery chillies. Fresh, not dried.',
                    ],
                ],
            ],
        ];
    }
}
