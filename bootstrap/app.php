<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureFeature;
use App\Http\Middleware\EnsureSuperadmin;
use App\Http\Middleware\OptionalAuth;
use App\Http\Middleware\ResetRequestState;
use App\Http\Middleware\ResolveCafeContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Rate limits are per client IP and must see the real one through the
        // nginx/Caddy in front of the app (§7.3).
        $middleware->trustProxies(at: '*');

        // Must run before anything reads the user or the cafe.
        $middleware->api(prepend: [ResetRequestState::class]);

        /*
         * Never redirect a guest — this app has no `login` route.
         *
         * Laravel's ApplicationBuilder installs a default redirect callback that
         * calls route('login'), and Authenticate runs it BEFORE it throws
         * AuthenticationException. So a browser NAVIGATION to an API route (a
         * PDF opened in a new tab, say) died with a 500 "Route [login] not
         * defined" that no exception renderer could intercept, because the
         * RouteNotFoundException is raised inside the middleware itself.
         * Returning null keeps every unauthenticated request on the 401 path.
         */
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'cafe' => ResolveCafeContext::class,
            'admin' => EnsureAdmin::class,
            'feature' => EnsureFeature::class,
            'superadmin' => EnsureSuperadmin::class,
            'optional.auth' => OptionalAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // This is an API. Every error is JSON, whatever the client asked for.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);
    })->create();
