<?php

namespace App\Http\Controllers\Api\V1\Farms;

use App\Enums\FarmerType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Farms\StoreFarmRequest;
use App\Http\Requests\Farms\UpdateFarmRequest;
use App\Http\Resources\FarmLocationResource;
use App\Http\Resources\FarmResource;
use App\Models\Farm;
use App\Services\Farms\FarmService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class FarmController extends Controller
{
    /**
     * Public directory of verified farms.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', Rule::in(config('mundalinq.countries.ZW.provinces'))],
            'district' => ['nullable', 'string', 'max:80'],
            'farmer_type' => ['nullable', Rule::enum(FarmerType::class)],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $farms = Farm::query()
            ->approved()
            ->with('primaryLocation')
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->whereLike('name', '%'.addcslashes($q, '%_\\').'%'))
            ->when($filters['farmer_type'] ?? null, fn ($query, $type) => $query->where('farmer_type', $type))
            ->when($filters['province'] ?? null, fn ($query, $province) => $query->whereHas(
                'locations', fn ($location) => $location->where('province', $province)
            ))
            ->when($filters['district'] ?? null, fn ($query, $district) => $query->whereHas(
                'locations', fn ($location) => $location->whereLike('district', addcslashes($district, '%_\\'))
            ))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return FarmResource::collection($farms);
    }

    public function show(Request $request, Farm $farm): FarmResource
    {
        // Public route: the token is optional, so resolve the user through the Sanctum guard directly.
        $user = $request->user('sanctum');
        Gate::forUser($user)->authorize('view', $farm);

        if ($user && ($user->isStaff() || $farm->memberRole($user) !== null)) {
            FarmLocationResource::revealExactCoordinates($request);
        }

        return new FarmResource($farm->load('locations'));
    }

    public function store(StoreFarmRequest $request, FarmService $farms): FarmResource
    {
        $farm = $farms->create($request->user(), $request->farmAttributes(), $request->locationAttributes());
        FarmLocationResource::revealExactCoordinates($request);

        return new FarmResource($farm->load('locations'));
    }

    public function update(UpdateFarmRequest $request, Farm $farm): FarmResource
    {
        $farm->update($request->farmAttributes());
        FarmLocationResource::revealExactCoordinates($request);

        return new FarmResource($farm->load('locations'));
    }
}
