<?php

namespace App\Services\Admin;

use App\Enums\NotificationCategory;
use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Notifications\MarketplaceAlert;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Account actions staff take on other people's accounts. Everything is audited.
 */
class UserAdministration
{
    private const STAFF_ROLES = [RoleSlug::SuperAdmin, RoleSlug::Admin, RoleSlug::SupportAgent];

    public function __construct(private AuditLogger $audit) {}

    /**
     * Blocks the account and signs it out of every device.
     */
    public function suspend(User $user, User $admin, string $reason): User
    {
        if ($user->is($admin)) {
            throw new BusinessRuleException(__('You cannot suspend your own account.'));
        }

        if ($user->hasRole(...self::STAFF_ROLES) && ! $admin->hasRole(RoleSlug::SuperAdmin)) {
            throw new BusinessRuleException(__('Only a super administrator can suspend staff accounts.'), 403);
        }

        if ($user->isSuspended()) {
            throw new BusinessRuleException(__('This account is already suspended.'));
        }

        DB::transaction(function () use ($user, $admin, $reason) {
            $user->forceFill(['status' => UserStatus::Suspended, 'suspended_at' => now(), 'suspension_reason' => $reason])->save();
            $user->tokens()->delete();

            $this->audit->record($admin, 'user.suspended', $user, ['reason' => $reason]);
        });

        $user->notify(new MarketplaceAlert(
            NotificationCategory::Account,
            __('Account suspended'),
            __('Your :app account has been suspended: :reason Contact support if you think this is a mistake.', [
                'app' => config('app.name'),
                'reason' => rtrim($reason, '.').'.',
            ]),
            sms: true,
        ));

        return $user;
    }

    public function reactivate(User $user, User $admin): User
    {
        if (! $user->isSuspended()) {
            throw new BusinessRuleException(__('This account is not suspended.'));
        }

        $user->forceFill(['status' => UserStatus::Active, 'suspended_at' => null, 'suspension_reason' => null])->save();
        $this->audit->record($admin, 'user.reactivated', $user);

        return $user;
    }

    public function grantRole(User $user, RoleSlug $role, User $superAdmin): User
    {
        if ($user->hasRole($role)) {
            throw new BusinessRuleException(__('This account already has that role.'));
        }

        $user->assignRole($role);
        $this->audit->record($superAdmin, 'user.role_granted', $user, ['role' => $role->value]);

        return $user;
    }

    public function revokeRole(User $user, RoleSlug $role, User $superAdmin): User
    {
        if ($user->is($superAdmin) && $role === RoleSlug::SuperAdmin) {
            throw new BusinessRuleException(__('You cannot remove your own super administrator role.'));
        }

        if (! $user->hasRole($role)) {
            throw new BusinessRuleException(__('This account does not have that role.'));
        }

        $user->removeRole($role);

        // Losing a staff role takes effect at once: sign out tokens that carried it.
        if (in_array($role, self::STAFF_ROLES, true)) {
            $user->tokens()->delete();
        }

        $this->audit->record($superAdmin, 'user.role_revoked', $user, ['role' => $role->value]);

        return $user;
    }
}
