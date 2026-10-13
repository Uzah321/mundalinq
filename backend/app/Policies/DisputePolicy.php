<?php

namespace App\Policies;

use App\Models\Dispute;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DisputePolicy
{
    /**
     * Read, write messages and add photos: the buyer, the farm's members and support staff.
     */
    public function view(User $user, Dispute $dispute): Response
    {
        return $dispute->partyOf($user) !== null ? Response::allow() : Response::denyAsNotFound();
    }

    public function withdraw(User $user, Dispute $dispute): Response
    {
        if ($dispute->buyer_id === $user->id) {
            return Response::allow();
        }

        return $this->view($user, $dispute)->allowed()
            ? Response::deny(__('Only the buyer can withdraw this report.'))
            : Response::denyAsNotFound();
    }
}
