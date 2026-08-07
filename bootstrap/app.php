<?php

use App\Http\Middleware\EnsureAdmin;
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

        $middleware->alias([
            'cafe' => ResolveCafeContext::class,
            'admin' => EnsureAdmin::class,
            'superadmin' => EnsureSuperadmin::class,
            'optional.auth' => OptionalAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);
    })->create();
