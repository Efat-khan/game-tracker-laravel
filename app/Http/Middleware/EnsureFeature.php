<?php

namespace App\Http\Middleware;

use App\Services\FeatureService;
use App\Support\Tenancy\CafeContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a module the platform owner has not granted this cafe.
 *
 * Hiding the sidebar item is presentation, not enforcement — without this a
 * cafe could still call the routes directly and use what it was not given.
 *
 * This answers 403, not the 404 the tenancy rules use. Those exist to avoid
 * confirming that ANOTHER tenant's record exists; here the caller is asking
 * about their own cafe, and "your plan does not include this" is the honest
 * and actionable answer.
 */
class EnsureFeature
{
    public function __construct(
        private readonly CafeContext $context,
        private readonly FeatureService $features,
    ) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! $this->features->enabled($this->context->id(), $feature)) {
            $label = FeatureService::CATALOGUE[$feature]['label'] ?? $feature;

            return response()->json([
                'message' => "{$label} is not enabled for this cafe. Ask the platform owner to turn it on.",
                'feature' => $feature,
            ], 403);
        }

        return $next($request);
    }
}
