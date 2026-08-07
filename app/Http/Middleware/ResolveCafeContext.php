<?php

namespace App\Http\Middleware;

use App\Models\Cafe;
use App\Support\Tenancy\CafeContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides which cafe this request acts on, and is the only thing allowed to.
 *
 * An admin/staff token is bound to its own cafe — any X-Cafe-Id the client
 * sends is ignored outright, so a tenant cannot widen its own reach. Only a
 * superadmin, who belongs to no cafe, names one per request (§4.1, §4.2).
 */
class ResolveCafeContext
{
    public function __construct(private readonly CafeContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->isSuperadmin()) {
            $header = $request->header('X-Cafe-Id');

            // Never a silent default to cafe 1.
            if ($header === null || trim($header) === '') {
                return response()->json([
                    'message' => 'A cafe must be selected. Send an X-Cafe-Id header.',
                ], 400);
            }

            if (! ctype_digit(ltrim(trim($header), '+'))) {
                return response()->json(['message' => 'Not found.'], 404);
            }

            $cafe = Cafe::find((int) trim($header));

            if ($cafe === null) {
                return response()->json(['message' => 'Not found.'], 404);
            }

            $this->context->bind($user, $cafe->id);

            return $next($request);
        }

        $this->context->bind($user, $user->cafe_id);

        return $next($request);
    }
}
