<?php

namespace Tests\Feature\Admin;

use App\Enums\LedgerEntryType;
use App\Enums\RoleSlug;
use App\Models\Farm;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Support\Market;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithCatalog;
use Tests\TestCase;

class AccountingReportTest extends TestCase
{
    use InteractsWithCatalog, RefreshDatabase;

    /**
     * A ledger entry at a local (market) time.
     */
    private function entry(Farm $farm, LedgerEntryType $type, int $minor, string $localTime): void
    {
        LedgerEntry::forceCreate([
            'farm_id' => $farm->id,
            'type' => $type,
            'amount_minor' => $minor,
            'currency' => 'USD',
            'description' => $type->value,
            'available_at' => now(),
            'created_at' => CarbonImmutable::parse($localTime, Market::timezone())->utc(),
        ]);
    }

    public function test_returns_403_for_support_agents(): void
    {
        Sanctum::actingAs(User::factory()->withRole(RoleSlug::SupportAgent)->create());

        $this->getJson('/api/v1/admin/reports/monthly?year=2026')->assertForbidden();
    }

    public function test_months_add_up_the_ledger_in_the_markets_time_zone(): void
    {
        [, $farm] = $this->farmerWithFarm();
        $this->entry($farm, LedgerEntryType::Sale, 1000, '2026-03-10 12:00');
        $this->entry($farm, LedgerEntryType::Commission, -50, '2026-03-10 12:00');
        $this->entry($farm, LedgerEntryType::Promotion, -100, '2026-03-20 09:00');
        // Just after midnight on 1 April in Harare, still 31 March in UTC.
        $this->entry($farm, LedgerEntryType::Sale, 500, '2026-04-01 00:30');
        $this->entry($farm, LedgerEntryType::Payout, -800, '2026-04-02 10:00');
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/reports/monthly?year=2026')
            ->assertOk()
            ->assertJsonCount(12, 'data')
            ->assertJsonPath('data.2.month', '2026-03')
            ->assertJsonPath('data.2.sales', '10.00')
            ->assertJsonPath('data.2.platform_fees', '0.50')
            ->assertJsonPath('data.2.featuring', '1.00')
            ->assertJsonPath('data.2.income', '1.50')
            ->assertJsonPath('data.3.sales', '5.00')
            ->assertJsonPath('data.3.payouts', '8.00');
    }

    public function test_farms_are_listed_by_sales_with_what_they_earned(): void
    {
        [, $big] = $this->farmerWithFarm(['name' => 'Big Farm']);
        [, $small] = $this->farmerWithFarm(['name' => 'Small Farm']);
        $this->entry($small, LedgerEntryType::Sale, 300, '2026-03-05 10:00');
        $this->entry($big, LedgerEntryType::Sale, 1000, '2026-03-06 10:00');
        $this->entry($big, LedgerEntryType::Commission, -50, '2026-03-06 10:00');
        $this->entry($big, LedgerEntryType::Refund, -200, '2026-03-07 10:00');
        $this->entry($big, LedgerEntryType::Promotion, -100, '2026-03-08 10:00');
        $this->entry($big, LedgerEntryType::Payout, -400, '2026-03-09 10:00');
        $this->entry($big, LedgerEntryType::Sale, 999, '2026-04-01 10:00');
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/reports/farms?month=2026-03')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.farm', 'Big Farm')
            ->assertJsonPath('data.0.sales', '10.00')
            ->assertJsonPath('data.0.refunds', '2.00')
            ->assertJsonPath('data.0.payouts', '4.00')
            ->assertJsonPath('data.0.net', '6.50')
            ->assertJsonPath('data.1.farm', 'Small Farm');
    }

    public function test_downloads_a_csv_that_excel_opens(): void
    {
        [, $farm] = $this->farmerWithFarm();
        $this->entry($farm, LedgerEntryType::Sale, 1000, '2026-03-10 12:00');
        $this->entry($farm, LedgerEntryType::Commission, -50, '2026-03-10 12:00');
        $this->actingAsAdmin();

        $response = $this->get('/api/v1/admin/reports/monthly?year=2026&format=csv')->assertOk();

        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('mundalinq-monthly-2026.csv', $response->headers->get('Content-Disposition'));
        $lines = explode("\n", trim($response->streamedContent()));
        $this->assertSame("\xEF\xBB\xBFMonth,\"Orders placed\",\"Orders completed\",Sales,\"Platform fees\",Featuring,\"MundaLinq income\",\"Refunds to buyers\",\"Payouts to farms\"", $lines[0]);
        $this->assertSame('2026-03,0,0,10.00,0.50,0.00,0.50,0.00,0.00', $lines[3]);
    }

    public function test_returns_422_for_a_month_that_does_not_exist(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/reports/farms?month=2026-13')->assertJsonValidationErrors('month');
    }
}
