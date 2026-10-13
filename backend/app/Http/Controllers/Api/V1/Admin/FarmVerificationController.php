<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\FarmVerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectFarmVerificationRequest;
use App\Http\Resources\Admin\FarmVerificationResource;
use App\Http\Resources\FarmLocationResource;
use App\Models\FarmVerification;
use App\Services\Farms\FarmVerificationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Reviewers need a farm's exact location to check it, so every response here reveals it.
 */
class FarmVerificationController extends Controller
{
    /**
     * Review queue; defaults to pending submissions, oldest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(FarmVerificationStatus::class)->except(FarmVerificationStatus::Unverified)],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $status = FarmVerificationStatus::from($filters['status'] ?? FarmVerificationStatus::Pending->value);

        $verifications = FarmVerification::query()
            ->where('status', $status)
            ->with(['farm.primaryLocation', 'submitter'])
            ->when($status === FarmVerificationStatus::Pending, fn ($query) => $query->oldest(), fn ($query) => $query->latest('reviewed_at'))
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        FarmLocationResource::revealExactCoordinates($request);

        return FarmVerificationResource::collection($verifications);
    }

    public function show(Request $request, FarmVerification $verification): FarmVerificationResource
    {
        FarmLocationResource::revealExactCoordinates($request);

        return new FarmVerificationResource($verification->load(['farm.locations', 'submitter.roles', 'reviewer', 'documents']));
    }

    public function approve(Request $request, FarmVerification $verification, FarmVerificationService $verifications): FarmVerificationResource
    {
        $verification = $verifications->approve($verification, $request->user());
        FarmLocationResource::revealExactCoordinates($request);

        return new FarmVerificationResource($verification->load(['farm', 'reviewer', 'documents']));
    }

    public function reject(RejectFarmVerificationRequest $request, FarmVerification $verification, FarmVerificationService $verifications): FarmVerificationResource
    {
        $verification = $verifications->reject($verification, $request->user(), $request->validated('reason'));
        FarmLocationResource::revealExactCoordinates($request);

        return new FarmVerificationResource($verification->load(['farm', 'reviewer', 'documents']));
    }
}
