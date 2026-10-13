<?php

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\User;
use Tests\Support\InteractsWithOrders;
use Tests\TestCase;

/**
 * Real concurrency: several PHP processes check out at the same moment against the same stock.
 *
 * The other processes must see committed data, so this test does not run inside a rolled-back
 * transaction; it rebuilds the test database before and after instead.
 */
class ConcurrentCheckoutTest extends TestCase
{
    use InteractsWithOrders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh')->run();
    }

    protected function tearDown(): void
    {
        // Leave an empty, migrated database for the transactional tests that follow.
        $this->artisan('migrate:fresh')->run();

        parent::tearDown();
    }

    public function test_simultaneous_checkouts_cannot_oversell_stock(): void
    {
        [, $farm] = $this->farmerWithFarm();
        $variant = $this->listing($farm, '10');

        $buyers = collect(range(1, 6))->map(function () use ($variant) {
            $buyer = $this->buyer();
            $this->addToCart($buyer, $variant, '3');

            return $buyer;
        });

        $startAt = microtime(true) + 3;
        $results = $buyers
            ->map(fn (User $buyer) => $this->spawn($buyer, $startAt))
            ->map(fn (array $process) => $this->finish($process));

        $summary = $results->implode(', ');
        $this->assertSame(3, $results->filter(fn (string $result) => $result === 'placed')->count(), "Three 3 kg orders fit in 10 kg; got: {$summary}");
        $this->assertSame(3, $results->filter(fn (string $result) => $result === 'refused')->count(), "Got: {$summary}");
        $this->assertSame('9.000', $variant->product->fresh()->stock_reserved);
        $this->assertSame(3, Order::count());
    }

    /**
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function spawn(User $buyer, float $startAt): array
    {
        $process = proc_open(
            [PHP_BINARY, base_path('tests/Support/concurrent-checkout.php'), $buyer->id, (string) $startAt],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            [...getenv(), 'APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database')],
        );

        return [$process, $pipes];
    }

    private function finish(array $process): string
    {
        [$handle, $pipes] = $process;
        $output = trim((string) stream_get_contents($pipes[1]));
        $errors = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($handle);

        return $output !== '' ? $output : 'error: '.$errors;
    }
}
