<?php

namespace App\Http\Middleware;

use App\Enums\RoleSlug;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('role:admin,super_admin') — the user needs any one of the listed roles.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = array_map(fn (string $role) => RoleSlug::from($role), $roles);

        abort_unless($request->user()?->hasRole(...$allowed), 403, __('You do not have permission to do this.'));

        return $next($request);
    }
}
