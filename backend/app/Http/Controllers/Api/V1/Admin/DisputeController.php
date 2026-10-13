<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DisputeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Disputes\ResolveDisputeRequest;
use App\Http\Resources\DisputeResource;
use App\Models\Dispute;
use App\Services\Disputes\DisputeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Support's queue. Details, messages and photos come from GET /disputes/{id}, which staff can read.
 */
class DisputeController extends Controller
{
    /**
     * Defaults to open reports, oldest first: the longest wait is answered first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(DisputeStatus::class)],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $status = DisputeStatus::from($filters['status'] ?? DisputeStatus::Open->value);

        $disputes = Dispute::where('status', $status)
            ->with(['sellerOrder', 'order', 'farm', 'buyer'])
            ->when($status === DisputeStatus::Open, fn ($query) => $query->oldest(), fn ($query) => $query->latest('updated_at'))
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return DisputeResource::collection($disputes);
    }

    /**
     * Body: {"resolution": "refund_full|refund_partial|no_refund", "refund_amount": "5.00" (partial only), "note": "..."}.
     */
    public function resolve(ResolveDisputeRequest $request, Dispute $dispute, DisputeService $disputes): DisputeResource
    {
        $dispute = $disputes->resolve($dispute, $request->user(), $request->resolution(), $request->partialRefundMinor(), $request->validated('note'));

        return new DisputeResource($dispute->load(DisputeResource::RELATIONS));
    }
}
