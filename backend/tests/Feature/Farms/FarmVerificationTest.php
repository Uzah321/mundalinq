<?php

namespace Tests\Feature\Farms;

use App\Enums\FarmMemberRole;
use App\Enums\FarmVerificationStatus;
use App\Enums\RoleSlug;
use App\Models\Farm;
use App\Models\FarmVerificationDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FarmVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function pdf(string $name = 'id.pdf', string $body = 'identity'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% {$body}\n");
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'id_document_type' => 'national_id',
            'id_number' => '63-123456A75',
            'documents' => [
                ['type' => 'identity', 'file' => $this->pdf()],
            ],
        ], $overrides);
    }

    private function ownedFarm(array $attributes = [], ?Farm $farm = null): array
    {
        $owner = User::factory()->withRole(RoleSlug::Farmer)->create();
        $farm ??= Farm::factory()->ownedBy($owner)->create($attributes);

        return [$owner, $farm];
    }

    public function test_owner_can_submit_verification_and_documents_are_encrypted_at_rest(): void
    {
        [$owner, $farm] = $this->ownedFarm();
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/farms/{$farm->id}/verification", $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.id_number', '*********A75')
            ->assertJsonPath('data.documents.0.type', 'identity');

        $this->assertSame(FarmVerificationStatus::Pending, $farm->fresh()->verification_status);

        $document = FarmVerificationDocument::sole();
        $stored = Storage::disk('local')->get($document->path);
        $this->assertStringNotContainsString('%PDF', $stored);
        $this->assertStringNotContainsString('63-123456A75', (string) $farm->verifications()->toBase()->value('id_number'));

        $this->getJson("/api/v1/farms/{$farm->id}/verification")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_identity_document_is_required(): void
    {
        [$owner, $farm] = $this->ownedFarm();
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/farms/{$farm->id}/verification", $this->payload([
            'documents' => [['type' => 'land_proof', 'file' => $this->pdf('lease.pdf')]],
        ]))->assertJsonValidationErrors('documents');
    }

    public function test_commercial_farms_must_prove_business_registration(): void
    {
        $owner = User::factory()->withRole(RoleSlug::Farmer)->create();
        $farm = Farm::factory()->commercial()->ownedBy($owner)->create();
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/farms/{$farm->id}/verification", $this->payload())
            ->assertJsonValidationErrors(['business_registration_number', 'documents']);

        $this->postJson("/api/v1/farms/{$farm->id}/verification", $this->payload([
            'business_registration_number' => 'CR 1234/2019',
            'documents' => [
                ['type' => 'identity', 'file' => $this->pdf()],
                ['type' => 'business_registration', 'file' => $this->pdf('cert.pdf')],
            ],
        ]))->assertCreated()->assertJsonPath('data.has_business_registration', true);
    }

    public function test_only_documents_of_allowed_types_are_accepted(): void
    {
        [$owner, $farm] = $this->ownedFarm();
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/farms/{$farm->id}/verification", $this->payload([
            'documents' => [['type' => 'identity', 'file' => UploadedFile::fake()->createWithContent('id.exe', "MZ\x90\x00binary")]],
        ]))->assertJsonValidationErrors('documents.0.file');

        Storage::disk('local')->assertDirectoryEmpty('/');
    }

    public function test_cannot_submit_while_a_submission_is_pending(): void
    {
        [$owner, $farm] = $this->ownedFarm();
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/farms/{$farm->id}/verification", $this->payload())->assertCreated();
        $this->postJson("/api/v1/farms/{$farm->id}/verification", $this->payload())->assertConflict();

        $this->assertSame(1, $farm->verifications()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_only_the_owner_can_submit(): void
    {
        [$owner, $farm] = $this->ownedFarm();
        $manager = User::factory()->withRole(RoleSlug::Farmer)->create();
        $farm->members()->attach($manager->id, ['role' => FarmMemberRole::Manager->value]);
        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/farms/{$farm->id}/verification", $this->payload())->assertForbidden();
    }

    public function test_rejected_farm_can_resubmit(): void
    {
        [$owner, $farm] = $this->ownedFarm(['verification_status' => FarmVerificationStatus::Rejected]);
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/farms/{$farm->id}/verification", $this->payload())->assertCreated();
        $this->assertSame(FarmVerificationStatus::Pending, $farm->fresh()->verification_status);
    }
}
