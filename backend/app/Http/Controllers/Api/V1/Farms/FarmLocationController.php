<?php

namespace App\Http\Controllers\Api\V1\Farms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Farms\StoreFarmLocationRequest;
use App\Http\Requests\Farms\UpdateFarmLocationRequest;
use App\Http\Resources\FarmLocationResource;
use App\Models\Farm;
use App\Models\FarmLocation;
use App\Services\Farms\FarmService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class FarmLocationController extends Controller
{
    public function store(StoreFarmLocationRequest $request, Farm $farm, FarmService $farms): FarmLocationResource
    {
        FarmLocationResource::revealExactCoordinates($request);

        return new FarmLocationResource($farms->addLocation($farm, $request->validated()));
    }

    public function update(UpdateFarmLocationRequest $request, Farm $farm, FarmLocation $location, FarmService $farms): FarmLocationResource
    {
        FarmLocationResource::revealExactCoordinates($request);

        return new FarmLocationResource($farms->updateLocation($location, $request->validated()));
    }

    public function destroy(Request $request, Farm $farm, FarmLocation $location, FarmService $farms): Response
    {
        Gate::authorize('update', $farm);

        $farms->deleteLocation($location);

        return response()->noContent();
    }
}
