<?php

namespace App\Policies;

use App\Enums\FarmMemberRole;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class FarmPolicy
{
    /**
     * Approved farms are public. Unapproved farms are visible only to their members
     * and staff, and look like they do not exist to everyone else.
     */
    public function view(?User $user, Farm $farm): Response
    {
        $visible = $farm->isApproved()
            || ($user && ($user->isStaff() || $farm->memberRole($user) !== null));

        return $visible ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * Edit the profile and locations.
     */
    public function update(User $user, Farm $farm): Response
    {
        return $this->memberWithRole($user, $farm, FarmMemberRole::Owner, FarmMemberRole::Manager);
    }

    /**
     * Submit identity and business documents.
     */
    public function manageVerification(User $user, Farm $farm): Response
    {
        return $this->memberWithRole($user, $farm, FarmMemberRole::Owner);
    }

    /**
     * Read and answer the farm's messages.
     */
    public function message(User $user, Farm $farm): Response
    {
        return $this->memberWithRole($user, $farm, FarmMemberRole::Owner, FarmMemberRole::Manager);
    }

    /**
     * Earnings and payouts are the owner's business.
     */
    public function viewEarnings(User $user, Farm $farm): Response
    {
        return $this->memberWithRole($user, $farm, FarmMemberRole::Owner);
    }

    /**
     * Pay for extras, such as featuring a listing, out of the farm's earnings.
     */
    public function spendEarnings(User $user, Farm $farm): Response
    {
        return $this->memberWithRole($user, $farm, FarmMemberRole::Owner);
    }

    /**
     * Set where the farm's money is paid.
     */
    public function managePayouts(User $user, Farm $farm): Response
    {
        return $this->memberWithRole($user, $farm, FarmMemberRole::Owner);
    }

    private function memberWithRole(User $user, Farm $farm, FarmMemberRole ...$roles): Response
    {
        $role = $farm->memberRole($user);

        if ($role === null) {
            return $this->view($user, $farm)->allowed()
                ? Response::deny(__('You are not a member of this farm.'))
                : Response::denyAsNotFound();
        }

        return in_array($role, $roles, true)
            ? Response::allow()
            : Response::deny(__('Only the farm owner can do this.'));
    }
}
