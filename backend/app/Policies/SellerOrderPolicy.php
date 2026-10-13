<?php

namespace App\Policies;

use App\Models\SellerOrder;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SellerOrderPolicy
{
    /**
     * The buyer, the farm's members, and staff.
     */
    public function view(User $user, SellerOrder $sellerOrder): Response
    {
        $visible = $user->isStaff()
            || $sellerOrder->order->buyer_id === $user->id
            || $sellerOrder->farm->memberRole($user) !== null;

        return $visible ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * Answer, prepare and hand over the order: the farm's owners and managers.
     */
    public function fulfil(User $user, SellerOrder $sellerOrder): Response
    {
        if ($sellerOrder->farm->memberRole($user) !== null) {
            return Response::allow();
        }

        return $this->view($user, $sellerOrder)->allowed()
            ? Response::deny(__('Only the farm can do this.'))
            : Response::denyAsNotFound();
    }

    public function confirmReceipt(User $user, SellerOrder $sellerOrder): Response
    {
        return $this->buyerOnly($user, $sellerOrder, __('Only the buyer can confirm receipt.'));
    }

    public function review(User $user, SellerOrder $sellerOrder): Response
    {
        return $this->buyerOnly($user, $sellerOrder, __('Only the buyer can review this order.'));
    }

    public function dispute(User $user, SellerOrder $sellerOrder): Response
    {
        return $this->buyerOnly($user, $sellerOrder, __('Only the buyer can report a problem with this order.'));
    }

    private function buyerOnly(User $user, SellerOrder $sellerOrder, string $message): Response
    {
        if ($sellerOrder->order->buyer_id === $user->id) {
            return Response::allow();
        }

        return $this->view($user, $sellerOrder)->allowed()
            ? Response::deny($message)
            : Response::denyAsNotFound();
    }
}
