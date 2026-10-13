<?php

namespace Tests\Feature\Farms;

use App\Enums\FarmMemberRole;
use App\Enums\RoleSlug;
use App\Models\Farm;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\SellerOrderService;
use App\Services\Settlement\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithOrders;
use Tests\TestCase;

class PayoutTest extends TestCase
{
    use InteractsWithOrders, RefreshDatabase;

    /**
     * A farm with one completed $12.00 sale: $11.40 available after the platform fee.
     *
     * @return array{0: User, 1: Farm}
     */
    private function farmWithEarnings(): array
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $buyer = $this->buyer();
        $sellerOrder = $this->paidOrder($buyer, $this->listing($farm))->sellerOrders()->first();
        $service = app(SellerOrderService::class);
        $service->markReady($sellerOrder, $farmer);
        $service->handOver($sellerOrder, $farmer, $sellerOrder->fresh()->handover_code);
        app(OrderService::class)->confirmReceipt($sellerOrder, $buyer);

        return [$farmer, $farm];
    }

    public function test_owner_sets_the_payout_account_and_every_owner_is_alerted(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $coOwner = User::factory()->withRole(RoleSlug::Farmer)->create();
        $farm->members()->attach($coOwner->id, ['role' => FarmMemberRole::Owner->value]);
        Sanctum::actingAs($farmer);

        $this->getJson("/api/v1/farms/{$farm->id}/payout-account")->assertOk()->assertJsonPath('data', null);

        $this->putJson("/api/v1/farms/{$farm->id}/payout-account", [
            'provider' => 'EcoCash',
            'account_name' => 'Chipo Moyo',
            'account_number' => '077 123 4567',
        ])->assertCreated()
            ->assertJsonPath('data.account_number_last4', '4567')
            ->assertJsonMissingPath('data.account_number')
            ->assertJsonPath('data.on_hold_until', fn (?string $until) => $until !== null);

        $this->assertStringContainsString('will now go to EcoCash ending 4567', last($this->smsTo($coOwner->phone)));
        $this->assertStringNotContainsString('+263771234567', (string) DB::table('farm_payout_accounts')->value('account_number'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'farm.payout_account_changed', 'subject_id' => $farm->id]);

        // Saving the same details again is not a change: no alert, no new hold.
        $smsCount = count($this->smsTo($coOwner->phone));
        $this->putJson("/api/v1/farms/{$farm->id}/payout-account", ['provider' => 'EcoCash', 'account_name' => 'Chipo Moyo', 'account_number' => '0771234567'])->assertOk();
        $this->assertCount($smsCount, $this->smsTo($coOwner->phone));
    }

    public function test_only_owners_with_a_verified_phone_change_the_payout_account(): void
    {
        [, $farm] = $this->farmerWithFarm();
        $payload = ['provider' => 'EcoCash', 'account_name' => 'Thief', 'account_number' => '0779999999'];

        $manager = User::factory()->withRole(RoleSlug::Farmer)->create();
        $farm->members()->attach($manager->id, ['role' => FarmMemberRole::Manager->value]);
        Sanctum::actingAs($manager);
        $this->putJson("/api/v1/farms/{$farm->id}/payout-account", $payload)->assertForbidden();

        $unverifiedOwner = User::factory()->unverifiedPhone()->withRole(RoleSlug::Farmer)->create();
        $farm->members()->attach($unverifiedOwner->id, ['role' => FarmMemberRole::Owner->value]);
        Sanctum::actingAs($unverifiedOwner);
        $this->putJson("/api/v1/farms/{$farm->id}/payout-account", $payload)->assertForbidden();

        $this->assertDatabaseCount('farm_payout_accounts', 0);
    }

    public function test_admin_pays_out_the_available_balance(): void
    {
        [$farmer, $farm] = $this->farmWithEarnings();
        app(PayoutService::class)->saveAccount($farm, $farmer, 'EcoCash', 'Chipo Moyo', '+263771234567');
        $this->travel(25)->hours();
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/payouts/due')
            ->assertJsonPath('data.0.farm.id', $farm->id)
            ->assertJsonPath('data.0.available', '11.40')
            ->assertJsonPath('data.0.payout_account.account_number_last4', '4567');

        $this->postJson("/api/v1/admin/farms/{$farm->id}/payouts", ['amount' => '11.40', 'reference' => 'ECO-998877'])
            ->assertCreated()
            ->assertJsonPath('data.amount', '11.40')
            ->assertJsonPath('data.account_number_last4', '4567');
        $this->postJson("/api/v1/admin/farms/{$farm->id}/payouts", ['amount' => '0.01', 'reference' => 'ECO-998878'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'You can pay out at most $0.00.');
        $this->getJson('/api/v1/admin/payouts/due')->assertJsonCount(0, 'data');

        $this->assertStringContainsString('$11.40 has been sent to your EcoCash account ending 4567 (reference ECO-998877)', last($this->smsTo($farmer->phone)));

        Sanctum::actingAs($farmer);
        $this->getJson("/api/v1/farms/{$farm->id}/earnings")->assertJsonPath('data.available', '0.00');
        $this->getJson("/api/v1/farms/{$farm->id}/payouts")->assertJsonPath('data.0.reference', 'ECO-998877');
    }

    public function test_payouts_need_an_account_that_was_not_just_changed(): void
    {
        [$farmer, $farm] = $this->farmWithEarnings();
        $this->actingAsAdmin();
        $payout = ['amount' => '5.00', 'reference' => 'ECO-1'];

        $this->postJson("/api/v1/admin/farms/{$farm->id}/payouts", $payout)
            ->assertConflict()
            ->assertJsonPath('message', 'This farm has not set up a payout account yet.');

        app(PayoutService::class)->saveAccount($farm, $farmer, 'EcoCash', 'Chipo Moyo', '+263771234567');
        $this->postJson("/api/v1/admin/farms/{$farm->id}/payouts", $payout)
            ->assertConflict()
            ->assertJsonPath('message', fn (string $message) => str_starts_with($message, 'The payout account was changed recently.'));
    }

    public function test_farmers_cannot_record_payouts(): void
    {
        [$farmer, $farm] = $this->farmWithEarnings();
        Sanctum::actingAs($farmer);

        $this->postJson("/api/v1/admin/farms/{$farm->id}/payouts", ['amount' => '11.40', 'reference' => 'ME'])->assertForbidden();
    }
}
