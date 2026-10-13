<?php

namespace App\Http\Controllers\Api\V1\Farms;

use App\Http\Controllers\Controller;
use App\Http\Resources\FarmLocationResource;
use App\Http\Resources\FarmResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Farms the signed-in user owns or manages, whatever their verification status.
 */
class MyFarmController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $farms = $request->user()->farms()
            ->with('primaryLocation')
            ->orderBy('farms.created_at')
            ->get();

        FarmLocationResource::revealExactCoordinates($request);

        return FarmResource::collection($farms);
    }
}
