<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RoleSlug;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddRoleRequest;
use App\Http\Resources\UserResource;

/**
 * Lets a buyer start selling (or a farmer start buying) without a second account.
 */
class MyRoleController extends Controller
{
    public function store(AddRoleRequest $request): UserResource
    {
        $request->user()->assignRole(RoleSlug::from($request->validated('role')));

        return new UserResource($request->user()->load('roles'));
    }
}
