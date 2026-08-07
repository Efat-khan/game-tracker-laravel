<?php

namespace App\Providers;

use App\Services\TokenService;
use App\Support\Tenancy\CafeContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One resolved cafe per request, shared by every query in it.
        $this->app->scoped(CafeContext::class);
    }

    public function boot(): void
    {
        // Bearer-token guard. Returning null here is what produces a 401, and it
        // covers three cases at once: no token, a bad signature or expiry, and a
        // token whose version claim was left behind by a password change, role
        // change or forced sign-out (§7.1).
        Auth::viaRequest('cafetrack', function (Request $request) {
            $token = $request->bearerToken();

            if ($token === null || $token === '') {
                return null;
            }

            return $this->app->make(TokenService::class)->resolve($token);
        });

        $this->registerRateLimits();
    }

    /**
     * §7.3 — per client IP, one-minute windows. The IP honours X-Forwarded-For
     * because bootstrap/app.php trusts the proxy in front of the app. Setting a
     * limit to 0 disables it, which is what the test suite does when it needs to
     * hammer an endpoint.
     */
    private function registerRateLimits(): void
    {
        foreach (['checkin', 'login', 'public_station'] as $name) {
            RateLimiter::for($name, function (Request $request) use ($name) {
                $perMinute = (int) config("cafetrack.rate_limits.{$name}");

                return $perMinute <= 0
                    ? Limit::none()
                    : Limit::perMinute($perMinute)->by($name.'|'.$request->ip());
            });
        }
    }
}
