<?php

namespace Tests\Feature\Admin;

use App\Enums\FarmVerificationStatus;
use App\Enums\RoleSlug;
use App\Models\AuditLog;
use App\Models\Farm;
use App\Models\FarmVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FarmVerificationReviewTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n% national id scan\n";

    private User $owner;

    private Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->owner = User::factory()->withRole(RoleSlug::Farmer)->create();
        $this->farm = Farm::factory()->ownedBy($this->owner)->create(['name' => 'Moyo Farm']);
    }

    private function submit(): FarmVerification
    {
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/farms/{$this->farm->id}/verification", [
            'id_document_type' => 'national_id',
            'id_number' => '63-123456A75',
            'documents' => [['type' => 'identity', 'file' => UploadedFile::fake()->createWithContent('id.pdf', self::PDF)]],
        ])->assertCreated();

        return FarmVerification::sole();
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->withRole(RoleSlug::Admin)->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_non_admins_cannot_access_the_review_queue(): void
    {
        $verification = $this->submit();

        foreach ([RoleSlug::Farmer, RoleSlug::Buyer, RoleSlug::SupportAgent] as $role) {
            Sanctum::actingAs(User::factory()->withRole($role)->create());

            $this->getJson('/api/v1/admin/farm-verifications')->assertForbidden();
            $this->postJson("/api/v1/admin/farm-verifications/{$verification->id}/approve")->assertForbidden();
        }
    }

    public function test_admin_sees_pending_queue_with_full_identity_details(): void
    {
        $verification = $this->submit();
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/farm-verifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.farm.name', 'Moyo Farm');

        $this->getJson("/api/v1/admin/farm-verifications/{$verification->id}")
            ->assertOk()
            ->assertJsonPath('data.id_number', '63-123456A75')
            ->assertJsonPath('data.submitted_by.id', $this->owner->id)
            ->assertJsonCount(1, 'data.documents');
    }

    public function test_admin_can_approve_and_farmer_is_notified(): void
    {
        $verification = $this->submit();
        $admin = $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/farm-verifications/{$verification->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.reviewed_by.id', $admin->id);

        $farm = $this->farm->fresh();
        $this->assertSame(FarmVerificationStatus::Approved, $farm->verification_status);
        $this->assertNotNull($farm->verified_at);
        $this->assertStringContainsString('Moyo Farm has been verified', end($this->sms->messages)['message']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'farm.verification_approved', 'actor_id' => $admin->id]);

        $this->getJson("/api/v1/farms/{$farm->id}")->assertOk();
    }

    public function test_admin_can_reject_with_reason_and_farmer_sees_it(): void
    {
        $verification = $this->submit();
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/farm-verifications/{$verification->id}/reject")
            ->assertJsonValidationErrors('reason');

        $this->postJson("/api/v1/admin/farm-verifications/{$verification->id}/reject", [
            'reason' => 'ID photo is blurry',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertSame(FarmVerificationStatus::Rejected, $this->farm->fresh()->verification_status);
        $this->assertStringContainsString('ID photo is blurry.', end($this->sms->messages)['message']);

        Sanctum::actingAs($this->owner);
        $this->getJson("/api/v1/farms/{$this->farm->id}/verification")
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'ID photo is blurry');
    }

    public function test_a_verification_can_only_be_reviewed_once(): void
    {
        $verification = $this->submit();
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/farm-verifications/{$verification->id}/approve")->assertOk();
        $this->postJson("/api/v1/admin/farm-verifications/{$verification->id}/reject", ['reason' => 'Changed my mind'])
            ->assertConflict();

        $this->assertSame(FarmVerificationStatus::Approved, $this->farm->fresh()->verification_status);
    }

    public function test_admin_can_download_decrypted_document_and_access_is_audited(): void
    {
        $verification = $this->submit();
        $admin = $this->actingAsAdmin();
        $document = $verification->documents()->sole();

        $response = $this->get("/api/v1/admin/farm-verifications/{$verification->id}/documents/{$document->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertSame(self::PDF, $response->getContent());
        $this->assertTrue(AuditLog::where('action', 'farm.verification_document_viewed')
            ->where('actor_id', $admin->id)
            ->where('subject_id', $document->id)
            ->exists());
    }

    public function test_document_must_belong_to_the_verification_in_the_url(): void
    {
        $verification = $this->submit();
        $document = $verification->documents()->sole();
        $otherVerification = new FarmVerification(['status' => 'pending', 'id_document_type' => 'passport', 'id_number' => 'FN123456']);
        $otherVerification->farm_id = Farm::factory()->create()->id;
        $otherVerification->submitted_by = $this->owner->id;
        $otherVerification->save();
        $this->actingAsAdmin();

        $this->getJson("/api/v1/admin/farm-verifications/{$otherVerification->id}/documents/{$document->id}")
            ->assertNotFound();
    }
}
