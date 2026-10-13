<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProductPolicy
{
    /**
     * Live listings are public; drafts, paused and rejected listings exist only for their farm and staff.
     */
    public function view(?User $user, Product $product): Response
    {
        $visible = $product->isVisibleToBuyers()
            || ($user && ($user->isStaff() || $product->farm?->memberRole($user) !== null));

        return $visible ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * Edit the listing, its selling options, photos and stock: any owner or manager of the farm.
     */
    public function manage(User $user, Product $product): Response
    {
        if ($product->farm?->memberRole($user) !== null) {
            return Response::allow();
        }

        return $this->view($user, $product)->allowed()
            ? Response::deny(__('You are not a member of this farm.'))
            : Response::denyAsNotFound();
    }
}
