<?php

namespace Database\Factories;

use App\Enums\FarmerType;
use App\Enums\FarmMemberRole;
use App\Enums\FarmVerificationStatus;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Farm>
 */
class FarmFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->lastName().' Farm',
            'description' => fake()->sentence(12),
            'farmer_type' => FarmerType::Smallholder,
            'contact_phone' => fake()->numerify('+26371#######'),
            'offers_pickup' => true,
            'offers_delivery' => false,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Farm $farm) {
            if (! $farm->locations()->exists()) {
                $farm->locations()->create([
                    'name' => 'Main farm',
                    'province' => 'Mashonaland East',
                    'district' => 'Goromonzi',
                    'ward' => '12',
                    'latitude' => fake()->latitude(-18.3, -17.6),
                    'longitude' => fake()->longitude(31.0, 31.6),
                    'is_primary' => true,
                ]);
            }
        });
    }

    public function ownedBy(User $user, FarmMemberRole $role = FarmMemberRole::Owner): static
    {
        return $this->afterCreating(fn (Farm $farm) => $farm->members()->attach($user->id, ['role' => $role->value]));
    }

    public function commercial(): static
    {
        return $this->state(['farmer_type' => FarmerType::Commercial]);
    }

    public function pending(): static
    {
        return $this->state(['verification_status' => FarmVerificationStatus::Pending]);
    }

    public function approved(): static
    {
        return $this->state(['verification_status' => FarmVerificationStatus::Approved, 'verified_at' => now()]);
    }
}
