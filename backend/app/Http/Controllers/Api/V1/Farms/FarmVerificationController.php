<?php

namespace App\Http\Controllers\Api\V1\Farms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Farms\SubmitFarmVerificationRequest;
use App\Http\Resources\FarmVerificationResource;
use App\Models\Farm;
use App\Services\Farms\FarmVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class FarmVerificationController extends Controller
{
    /**
     * The farm's most recent submission, so the app can show its status or rejection reason.
     */
    public function show(Farm $farm): JsonResponse|FarmVerificationResource
    {
        Gate::authorize('update', $farm);

        $verification = $farm->latestVerification()?->load('documents');

        return $verification
            ? new FarmVerificationResource($verification)
            : response()->json(['data' => null]);
    }

    public function store(SubmitFarmVerificationRequest $request, Farm $farm, FarmVerificationService $verifications): JsonResponse
    {
        $verification = $verifications->submit($farm, $request->user(), $request->details(), $request->documents());

        return (new FarmVerificationResource($verification->load('documents')))
            ->response()
            ->setStatusCode(201);
    }
}
