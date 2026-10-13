<?php

namespace App\Http\Controllers\Api\V1\Disputes;

use App\Enums\DisputeReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Disputes\OpenDisputeRequest;
use App\Http\Requests\Disputes\UploadDisputeEvidenceRequest;
use App\Http\Resources\DisputeMessageResource;
use App\Http\Resources\DisputeResource;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\Farm;
use App\Models\SellerOrder;
use App\Services\Disputes\DisputeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Problems with delivered orders, as the buyer and the farm see them. Support decides them
 * through the admin endpoints.
 */
class DisputeController extends Controller
{
    public function __construct(private DisputeService $disputes) {}

    /**
     * Report a problem within the dispute window after handover.
     */
    public function store(OpenDisputeRequest $request, SellerOrder $sellerOrder): JsonResponse
    {
        $dispute = $this->disputes->open(
            $sellerOrder,
            $request->user(),
            DisputeReason::from($request->validated('reason')),
            $request->validated('description'),
            $request->requestedRefundMinor(),
        );

        return (new DisputeResource($dispute->load(DisputeResource::RELATIONS)))->response()->setStatusCode(201);
    }

    /**
     * The signed-in buyer's reports or, with farm_id, the reports about that farm. Newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['farm_id' => ['nullable', 'uuid']]);

        if (isset($filters['farm_id'])) {
            $farm = Farm::findOrFail($filters['farm_id']);
            Gate::authorize('message', $farm);
            $query = Dispute::where('farm_id', $farm->id);
        } else {
            $query = Dispute::where('buyer_id', $request->user()->id);
        }

        return DisputeResource::collection(
            $query->with(['sellerOrder', 'order', 'farm', 'buyer'])->latest()->orderByDesc('id')->paginate(20),
        );
    }

    public function show(Dispute $dispute): DisputeResource
    {
        Gate::authorize('view', $dispute);

        return new DisputeResource($dispute->load(DisputeResource::RELATIONS));
    }

    public function message(Request $request, Dispute $dispute): JsonResponse
    {
        Gate::authorize('view', $dispute);

        $validated = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $message = $this->disputes->addMessage($dispute, $request->user(), $dispute->partyOf($request->user()), $validated['body']);

        return (new DisputeMessageResource($message->load('author')))->response()->setStatusCode(201);
    }

    /**
     * Multipart: photo (jpg, png or webp). Re-encoded on upload, so location metadata is removed.
     */
    public function uploadEvidence(UploadDisputeEvidenceRequest $request, Dispute $dispute): DisputeResource
    {
        $this->disputes->addEvidence($dispute, $request->user(), $dispute->partyOf($request->user()), $request->file('photo'));

        return new DisputeResource($dispute->load(DisputeResource::RELATIONS));
    }

    public function showEvidence(Dispute $dispute, DisputeEvidence $evidence): StreamedResponse
    {
        Gate::authorize('view', $dispute);

        return Storage::disk($evidence->disk)->response($evidence->path, null, [
            'Content-Type' => $evidence->mime_type,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function withdraw(Request $request, Dispute $dispute): DisputeResource
    {
        Gate::authorize('withdraw', $dispute);

        return new DisputeResource($this->disputes->withdraw($dispute, $request->user())->load(DisputeResource::RELATIONS));
    }
}
