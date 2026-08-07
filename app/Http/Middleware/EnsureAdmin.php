<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-only routes. A superadmin passes once they have selected a cafe —
 * otherwise the platform owner could open a tenant but not fix anything in
 * it (§3).
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user->isAdmin()) {
            return response()->json(['message' => 'This action requires an admin account.'], 403);
        }

        return $next($request);
    }
}
