<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\FarmVerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\FarmResource;
use App\Http\Resources\FarmLocationResource;
use App\Models\Farm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class FarmController extends Controller
{
    /**
     * Farms with their owners' contact details, newest first. Search by farm name or owner phone with q.
     * With verification_status=unverified this is the follow-up list: farms that have not sent documents yet.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'verification_status' => ['nullable', Rule::enum(FarmVerificationStatus::class)],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $farms = Farm::query()
            ->when($filters['q'] ?? null, function ($query, string $q) {
                $term = '%'.addcslashes($q, '%_\\').'%';
                $query->where(fn ($match) => $match
                    ->whereLike('name', $term)
                    ->orWhereHas('owners', fn ($owners) => $owners->whereLike('phone', $term)->orWhereLike('users.name', $term)));
            })
            ->when($filters['verification_status'] ?? null, fn ($query, string $status) => $query->where('verification_status', $status))
            ->with(['owners', 'primaryLocation'])
            ->withCount('products')
            ->latest()
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        FarmLocationResource::revealExactCoordinates($request);

        return FarmResource::collection($farms);
    }
}
