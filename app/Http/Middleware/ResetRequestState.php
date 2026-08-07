<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CafeContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Clears per-request identity before anything reads it.
 *
 * RequestGuard memoises the resolved user and never clears it when the request
 * is swapped, and CafeContext is a scoped singleton. Under PHP-FPM that is
 * invisible — each request gets a fresh application — but under Octane, a
 * queue worker or the test suite the container outlives the request, and a
 * second request can inherit the FIRST request's user and cafe.
 *
 * For an app whose central guarantee is that one tenant never sees another's
 * data, that is not a risk worth carrying on the process model. This runs
 * first on every API request and makes the guarantee hold regardless of how
 * the app is served.
 */
class ResetRequestState
{
    public function __construct(private readonly CafeContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        Auth::forgetGuards();

        $this->context->bind(null, null);

        return $next($request);
    }
}
