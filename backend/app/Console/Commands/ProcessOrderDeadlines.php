<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Orders\OrderService;
use App\Services\Payments\PaymentService;
use Illuminate\Console\Command;

/**
 * Runs every minute (see routes/console.php). Each step re-checks state under lock, so running it
 * twice, or alongside user requests, is safe.
 */
class ProcessOrderDeadlines extends Command
{
    protected $signature = 'orders:process-deadlines';

    protected $description = 'Expire unanswered orders and abandoned payments, cancel unpaid orders, and finalise delivered ones';

    public function handle(OrderService $orders, PaymentService $payments): int
    {
        $expired = $orders->expireUnconfirmed();
        $abandoned = $payments->expireAbandoned();

        $cancelled = 0;
        $overdue = Order::where('status', OrderStatus::AwaitingPayment)->where('payment_due_at', '<=', now())->get();

        foreach ($overdue as $order) {
            // A payment approved at the last moment must win over the cancellation.
            $payments->refreshOpenPayments($order);

            if ($orders->cancelIfUnpaid($order)) {
                $cancelled++;
            }
        }

        $completed = $orders->completeUndisputed();

        $this->info("Expired {$expired} unanswered, {$abandoned} abandoned payments; cancelled {$cancelled} unpaid; completed {$completed} delivered.");

        return self::SUCCESS;
    }
}
