<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class OrderPolicy
{
    public function view(User $user, Order $order): Response
    {
        return $order->buyer_id === $user->id || $user->isStaff()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Pay for, cancel or confirm receipt of the order: the buyer only.
     */
    public function act(User $user, Order $order): Response
    {
        if ($order->buyer_id === $user->id) {
            return Response::allow();
        }

        return $user->isStaff() ? Response::deny(__('Only the buyer can do this.')) : Response::denyAsNotFound();
    }
}
