<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserAddress>
 */
class UserAddressFactory extends Factory
{
    /**
     * Defaults to central Harare.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => 'Home',
            'recipient_name' => fake()->name(),
            'phone' => fake()->numerify('+26377#######'),
            'province' => 'Harare',
            'district' => 'Harare',
            'address_line' => fake()->streetAddress(),
            'directions' => 'Blue gate opposite the clinic',
            'latitude' => -17.8292,
            'longitude' => 31.0522,
            'is_default' => true,
        ];
    }

    public function at(float $latitude, float $longitude): static
    {
        return $this->state(['latitude' => $latitude, 'longitude' => $longitude]);
    }
}
