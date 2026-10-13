<?php

namespace Database\Seeders;

use App\Enums\RoleSlug;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Reference data for every environment, plus demo accounts and listings locally.
     * Every demo account's password is "password".
     */
    public function run(): void
    {
        $this->call(CategorySeeder::class);

        if (! app()->isLocal()) {
            $this->command?->warn('Demo accounts and listings are only seeded in the local environment.');

            return;
        }

        $accounts = [
            ['Super Admin', '+263770000001', 'admin@mundalinq.test', RoleSlug::SuperAdmin],
            ['Demo Farmer', '+263770000002', 'farmer@mundalinq.test', RoleSlug::Farmer],
            ['Demo Buyer', '+263770000003', 'buyer@mundalinq.test', RoleSlug::Buyer],
        ];

        $users = [];

        foreach ($accounts as [$name, $phone, $email, $role]) {
            $users[$role->value] = User::factory()->withRole($role)->create(compact('name', 'phone', 'email'));
        }

        UserAddress::factory()->for($users['buyer'])->create([
            'recipient_name' => 'Demo Buyer',
            'phone' => '+263770000003',
            'address_line' => '12 Samora Machel Avenue',
        ]);

        // The demo farms, all owned by the demo farmer, and their listings.
        $this->call(DemoListingSeeder::class);
    }
}
