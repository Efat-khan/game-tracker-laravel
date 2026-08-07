<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Platform-owner routes: creating, renaming and suspending cafes. */
class EnsureSuperadmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user->isSuperadmin()) {
            return response()->json(['message' => 'This action requires the platform owner.'], 403);
        }

        return $next($request);
    }
}
