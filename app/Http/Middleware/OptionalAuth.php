<?php

namespace App\Http\Middleware;

use App\Services\TokenService;
use App\Support\Tenancy\CafeContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public routes that behave differently for signed-in staff.
 *
 * Check-in is the case that matters: a player scans a QR code and must present
 * its signed token, but staff starting a session from the dashboard have no
 * code to scan and bypass the check (§7.2).
 */
class OptionalAuth
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly CafeContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if ($bearer !== null && $bearer !== '') {
            $user = $this->tokens->resolve($bearer);

            if ($user !== null) {
                $request->setUserResolver(fn () => $user);
                $this->context->bind($user, $user->cafe_id);
            }
        }

        return $next($request);
    }
}
