<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\UserResource;
use App\Models\User;
use App\Services\Admin\UserAdministration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(private UserAdministration $users) {}

    /**
     * Search by name, phone or email; filter by role and status. Newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(RoleSlug::class)],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $users = User::query()
            ->when($filters['q'] ?? null, function ($query, string $q) {
                $term = '%'.addcslashes($q, '%_\\').'%';
                $query->where(fn ($match) => $match->whereLike('name', $term)->orWhereLike('phone', $term)->orWhereLike('email', $term));
            })
            ->when($filters['role'] ?? null, fn ($query, string $role) => $query->whereHas('roles', fn ($roles) => $roles->where('slug', $role)))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->with('roles')
            ->latest()
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return UserResource::collection($users);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->load(['roles', 'farms'])->loadCount('orders'));
    }

    public function suspend(Request $request, User $user): UserResource
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);

        return new UserResource($this->users->suspend($user, $request->user(), $validated['reason'])->load('roles'));
    }

    public function reactivate(Request $request, User $user): UserResource
    {
        return new UserResource($this->users->reactivate($user, $request->user())->load('roles'));
    }

    public function grantRole(Request $request, User $user): UserResource
    {
        $validated = $request->validate(['role' => ['required', Rule::enum(RoleSlug::class)]]);

        return new UserResource($this->users->grantRole($user, RoleSlug::from($validated['role']), $request->user())->load('roles'));
    }

    public function revokeRole(Request $request, User $user, string $role): UserResource
    {
        $slug = RoleSlug::tryFrom($role) ?? abort(404);

        return new UserResource($this->users->revokeRole($user, $slug, $request->user())->load('roles'));
    }
}
