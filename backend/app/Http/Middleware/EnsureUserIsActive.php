<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks suspended accounts even if they still hold a valid device token.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->user()?->isSuspended(), 403, __('This account has been suspended. Please contact support.'));

        return $next($request);
    }
}
