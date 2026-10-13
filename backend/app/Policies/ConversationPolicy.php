<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ConversationPolicy
{
    /**
     * The buyer, the farm's members, and staff (for support).
     */
    public function view(User $user, Conversation $conversation): Response
    {
        return $conversation->sideOf($user) !== null || $user->isStaff()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Write and mark read: only the two sides of the conversation.
     */
    public function participate(User $user, Conversation $conversation): Response
    {
        if ($conversation->sideOf($user) !== null) {
            return Response::allow();
        }

        return $user->isStaff()
            ? Response::deny(__('Only the buyer and the farm can write in this conversation.'))
            : Response::denyAsNotFound();
    }
}
