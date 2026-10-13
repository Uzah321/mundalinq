<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleSlug;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithOrders;
use Tests\TestCase;

class UserAdministrationTest extends TestCase
{
    use InteractsWithOrders, RefreshDatabase;

    private function superAdmin(): User
    {
        Sanctum::actingAs($superAdmin = User::factory()->withRole(RoleSlug::SuperAdmin)->create());

        return $superAdmin;
    }

    public function test_staff_find_accounts_by_phone_and_role(): void
    {
        $farmer = User::factory()->withRole(RoleSlug::Farmer)->create(['phone' => '+263775550001']);
        User::factory()->withRole(RoleSlug::Buyer)->create();
        Sanctum::actingAs(User::factory()->withRole(RoleSlug::SupportAgent)->create());

        $this->getJson('/api/v1/admin/users?q=5550001')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $farmer->id);
        $this->getJson('/api/v1/admin/users?role=farmer')->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/admin/users/{$farmer->id}")->assertJsonPath('data.roles', ['farmer']);
    }

    public function test_suspending_an_account_signs_it_out_everywhere(): void
    {
        $buyer = $this->buyer();
        $token = $buyer->createToken('Phone')->plainTextToken;
        $admin = $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/users/{$buyer->id}/suspend", ['reason' => 'Repeated fake orders'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.suspension_reason', 'Repeated fake orders');

        $this->assertStringContainsString('has been suspended: Repeated fake orders.', last($this->smsTo($buyer->phone)));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.suspended', 'actor_id' => $admin->id, 'subject_id' => $buyer->id]);
        $this->assertSame(0, $buyer->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();

        $this->actingAsAdmin();
        $this->postJson("/api/v1/admin/users/{$buyer->id}/reactivate")->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_admins_cannot_suspend_themselves_or_other_staff(): void
    {
        $otherAdmin = User::factory()->withRole(RoleSlug::Admin)->create();
        $admin = $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/users/{$admin->id}/suspend", ['reason' => 'Testing myself'])
            ->assertConflict()
            ->assertJsonPath('message', 'You cannot suspend your own account.');
        $this->postJson("/api/v1/admin/users/{$otherAdmin->id}/suspend", ['reason' => 'Disagreement'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Only a super administrator can suspend staff accounts.');

        $this->superAdmin();
        $this->postJson("/api/v1/admin/users/{$otherAdmin->id}/suspend", ['reason' => 'Left the company'])->assertOk();
    }

    public function test_support_agents_can_look_but_not_suspend(): void
    {
        $buyer = $this->buyer();
        Sanctum::actingAs(User::factory()->withRole(RoleSlug::SupportAgent)->create());

        $this->postJson("/api/v1/admin/users/{$buyer->id}/suspend", ['reason' => 'Because'])->assertForbidden();
    }

    public function test_super_admins_grant_and_revoke_staff_roles(): void
    {
        $user = User::factory()->create();
        $superAdmin = $this->superAdmin();

        $this->postJson("/api/v1/admin/users/{$user->id}/roles", ['role' => 'support_agent'])
            ->assertOk()
            ->assertJsonPath('data.roles', ['support_agent']);
        $this->postJson("/api/v1/admin/users/{$user->id}/roles", ['role' => 'support_agent'])->assertConflict();

        $this->deleteJson("/api/v1/admin/users/{$user->id}/roles/support_agent")->assertOk()->assertJsonPath('data.roles', []);
        $this->deleteJson("/api/v1/admin/users/{$superAdmin->id}/roles/super_admin")
            ->assertConflict()
            ->assertJsonPath('message', 'You cannot remove your own super administrator role.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.role_granted', 'subject_id' => $user->id]);

        $this->actingAsAdmin();
        $this->postJson("/api/v1/admin/users/{$user->id}/roles", ['role' => 'admin'])->assertForbidden();
        $this->getJson('/api/v1/admin/audit-logs')->assertForbidden();

        $this->superAdmin();
        $this->getJson('/api/v1/admin/audit-logs?action=user.role_granted')
            ->assertOk()
            ->assertJsonPath('data.0.subject_id', $user->id)
            ->assertJsonPath('data.0.metadata.role', 'support_agent');
    }
}
