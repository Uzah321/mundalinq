<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PaymentPolicy
{
    public function view(User $user, Payment $payment): Response
    {
        return $payment->buyer_id === $user->id || $user->isStaff()
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
