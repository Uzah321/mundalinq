<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\RefundStatus;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\RefundResource;
use App\Models\Refund;
use App\Services\Payments\RefundService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class RefundController extends Controller
{
    public function __construct(private RefundService $refunds) {}

    /**
     * Newest first. status=failed lists the refunds still owed that need attention.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(RefundStatus::class)],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $refunds = Refund::query()
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return RefundResource::collection($refunds);
    }

    public function retry(Refund $refund): RefundResource
    {
        if ($refund->status !== RefundStatus::Failed) {
            throw new BusinessRuleException(__('Only failed refunds can be retried.'));
        }

        return new RefundResource($this->refunds->send($refund));
    }

    /**
     * The money was sent by hand; record the transfer reference.
     */
    public function complete(Request $request, Refund $refund): RefundResource
    {
        $validated = $request->validate(['reference' => ['required', 'string', 'max:100']]);

        return new RefundResource($this->refunds->markPaidManually($refund, $request->user(), $validated['reference']));
    }
}
