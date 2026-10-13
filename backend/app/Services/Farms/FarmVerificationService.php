<?php

namespace App\Services\Farms;

use App\Enums\FarmVerificationStatus;
use App\Enums\NotificationCategory;
use App\Exceptions\BusinessRuleException;
use App\Models\Farm;
use App\Models\FarmVerification;
use App\Models\FarmVerificationDocument;
use App\Models\User;
use App\Notifications\MarketplaceAlert;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class FarmVerificationService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{id_document_type: string, id_number: string, business_registration_number?: ?string, notes?: ?string}  $details
     * @param  list<array{type: string, file: UploadedFile}>  $documents
     */
    public function submit(Farm $farm, User $submitter, array $details, array $documents): FarmVerification
    {
        $verificationId = (string) Str::uuid7();
        $stored = $this->storeDocuments($verificationId, $documents);

        try {
            return DB::transaction(function () use ($farm, $submitter, $details, $stored, $verificationId) {
                $farm = Farm::whereKey($farm->id)->lockForUpdate()->firstOrFail();

                if (in_array($farm->verification_status, [FarmVerificationStatus::Pending, FarmVerificationStatus::Approved], true)) {
                    throw new BusinessRuleException($farm->verification_status === FarmVerificationStatus::Pending
                        ? __('This farm already has a verification under review.')
                        : __('This farm is already verified.'));
                }

                $verification = new FarmVerification([...$details, 'status' => FarmVerificationStatus::Pending]);
                $verification->id = $verificationId;
                $verification->farm_id = $farm->id;
                $verification->submitted_by = $submitter->id;
                $verification->save();

                $verification->documents()->createMany($stored);

                $farm->forceFill(['verification_status' => FarmVerificationStatus::Pending])->save();

                $this->audit->record($submitter, 'farm.verification_submitted', $verification, ['farm_id' => $farm->id]);

                return $verification;
            });
        } catch (Throwable $e) {
            foreach ($stored as $document) {
                Storage::disk($document['disk'])->delete($document['path']);
            }

            throw $e;
        }
    }

    public function approve(FarmVerification $verification, User $reviewer): FarmVerification
    {
        $verification = $this->review($verification, $reviewer, FarmVerificationStatus::Approved);

        $this->notifySubmitter($verification, __('Farm verified'), __('Good news! :farm has been verified on :app. You can now list your produce.', [
            'farm' => $verification->farm->name,
            'app' => config('app.name'),
        ]));

        return $verification;
    }

    public function reject(FarmVerification $verification, User $reviewer, string $reason): FarmVerification
    {
        $verification = $this->review($verification, $reviewer, FarmVerificationStatus::Rejected, $reason);

        $this->notifySubmitter($verification, __('Farm not verified'), __(':farm could not be verified: :reason Please update your details in the :app app and resubmit.', [
            'farm' => $verification->farm->name,
            'reason' => Str::finish($reason, '.'),
            'app' => config('app.name'),
        ]));

        return $verification;
    }

    /**
     * Decrypt a stored document for an authorised viewer. Every access is audited.
     */
    public function readDocument(FarmVerificationDocument $document, User $viewer): string
    {
        $this->audit->record($viewer, 'farm.verification_document_viewed', $document, [
            'farm_verification_id' => $document->farm_verification_id,
        ]);

        return Crypt::decryptString(Storage::disk($document->disk)->get($document->path));
    }

    private function review(FarmVerification $verification, User $reviewer, FarmVerificationStatus $decision, ?string $reason = null): FarmVerification
    {
        return DB::transaction(function () use ($verification, $reviewer, $decision, $reason) {
            $verification = FarmVerification::whereKey($verification->id)->lockForUpdate()->firstOrFail();

            if (! $verification->isPending()) {
                throw new BusinessRuleException(__('This verification has already been reviewed.'));
            }

            $verification->forceFill([
                'status' => $decision,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $verification->farm->forceFill([
                'verification_status' => $decision,
                'verified_at' => $decision === FarmVerificationStatus::Approved ? now() : null,
            ])->save();

            $this->audit->record($reviewer, 'farm.verification_'.$decision->value, $verification, array_filter([
                'farm_id' => $verification->farm_id,
                'reason' => $reason,
            ]));

            return $verification;
        });
    }

    /**
     * Encrypt and store uploaded documents before the database transaction starts.
     *
     * @param  list<array{type: string, file: UploadedFile}>  $documents
     * @return list<array<string, mixed>>
     */
    private function storeDocuments(string $verificationId, array $documents): array
    {
        $disk = config('mundalinq.farms.verification_disk');
        $stored = [];

        try {
            foreach ($documents as $document) {
                $path = "farm-verifications/{$verificationId}/".Str::uuid7().'.enc';
                Storage::disk($disk)->put($path, Crypt::encryptString($document['file']->get()));

                $stored[] = [
                    'type' => $document['type'],
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => Str::limit($document['file']->getClientOriginalName(), 200, ''),
                    'mime_type' => $document['file']->getMimeType(),
                    'size_bytes' => $document['file']->getSize(),
                ];
            }
        } catch (Throwable $e) {
            foreach ($stored as $document) {
                Storage::disk($disk)->delete($document['path']);
            }

            throw $e;
        }

        return $stored;
    }

    private function notifySubmitter(FarmVerification $verification, string $title, string $message): void
    {
        $verification->submitter?->notify(new MarketplaceAlert(NotificationCategory::Account, $title, $message, [
            'farm_id' => $verification->farm_id,
        ], sms: true));
    }
}
