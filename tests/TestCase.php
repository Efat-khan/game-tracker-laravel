<?php

namespace Tests;

use App\Models\AdminUser;
use App\Models\Cafe;
use App\Models\Customer;
use App\Models\GameSession;
use App\Models\Station;
use App\Models\StationRate;
use App\Services\StationTokenService;
use App\Services\TokenService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Tests that need to prove a limit works turn it back on themselves.
        config()->set('cafetrack.rate_limits', [
            'checkin' => 0,
            'login' => 0,
            'public_station' => 0,
        ]);
    }

    protected function makeCafe(string $name = 'Test Cafe', bool $active = true): Cafe
    {
        return Cafe::create([
            'name' => $name,
            'slug' => Cafe::uniqueSlug($name),
            'is_active' => $active,
            'created_at' => now(),
        ]);
    }

    protected function makeUser(
        ?Cafe $cafe,
        string $role = 'admin',
        ?string $email = null,
        string $password = 'secret123',
    ): AdminUser {
        static $n = 0;
        $n++;

        return AdminUser::create([
            'cafe_id' => $cafe?->id,
            'email' => $email ?? "{$role}{$n}@example.com",
            'password_hash' => Hash::make($password),
            'role' => $role,
            'created_at' => now(),
        ]);
    }

    /**
     * A station with a price list.
     *
     * `rates` may be passed to override it; anything not given is filled from
     * the old flat shape (base + 50 per extra pad) so the billing tests keep
     * their familiar numbers.
     */
    protected function makeStation(Cafe $cafe, array $attributes = []): Station
    {
        $rates = $attributes['rates'] ?? null;
        unset($attributes['rates']);

        $station = Station::create(array_merge([
            'cafe_id' => $cafe->id,
            'name' => 'PS5 - Booth 1',
            'type' => 'PS5',
            'hourly_rate' => '150.00',
            'max_controllers' => 4,
            'created_at' => now(),
        ], $attributes));

        for ($n = 1; $n <= $station->max_controllers; $n++) {
            StationRate::create([
                'station_id' => $station->id,
                'controllers' => $n,
                'hourly_rate' => $rates[$n] ?? (string) Money::str(
                    Money::of($station->hourly_rate)->plus(Money::of('50')->multipliedBy($n - 1))
                ),
            ]);
        }

        return $station->load('rates');
    }

    protected function makeCustomer(Cafe $cafe, array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'cafe_id' => $cafe->id,
            'name' => 'Rafi Ahmed',
            'phone_or_id' => '01700000000',
            'created_at' => now(),
        ], $attributes));
    }

    /**
     * A session that started $minutesAgo minutes ago, so a checkout right now
     * bills for exactly that much time.
     */
    protected function makeSession(
        Cafe $cafe,
        Station $station,
        Customer $customer,
        int $minutesAgo = 60,
        array $attributes = [],
    ): GameSession {
        return GameSession::create(array_merge([
            'cafe_id' => $cafe->id,
            'station_id' => $station->id,
            'customer_id' => $customer->id,
            'start_time' => now()->subMinutes($minutesAgo),
            'status' => 'active',
            'hourly_rate_snapshot' => $station->hourly_rate,
            'base_rate_snapshot' => $station->hourly_rate,
            'extra_controller_rate_snapshot' => '50.00',
            'controllers' => 1,
            'created_at' => now()->subMinutes($minutesAgo),
        ], $attributes));
    }

    protected function tokenFor(AdminUser $user): string
    {
        return app(TokenService::class)->issue($user);
    }

    /**
     * Auth headers for a user. A superadmin has no cafe of their own, so tests
     * name one explicitly via X-Cafe-Id, exactly as a real client must.
     */
    protected function headersFor(AdminUser $user, ?Cafe $asCafe = null): array
    {
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        if ($asCafe !== null) {
            $headers['X-Cafe-Id'] = (string) $asCafe->id;
        }

        return $headers;
    }

    protected function apiGet(AdminUser $user, string $uri, ?Cafe $asCafe = null): TestResponse
    {
        return $this->getJson($uri, $this->headersFor($user, $asCafe));
    }

    protected function apiPost(AdminUser $user, string $uri, array $body = [], ?Cafe $asCafe = null): TestResponse
    {
        return $this->postJson($uri, $body, $this->headersFor($user, $asCafe));
    }

    protected function apiPatch(AdminUser $user, string $uri, array $body = [], ?Cafe $asCafe = null): TestResponse
    {
        return $this->patchJson($uri, $body, $this->headersFor($user, $asCafe));
    }

    protected function apiDelete(AdminUser $user, string $uri, ?Cafe $asCafe = null): TestResponse
    {
        return $this->deleteJson($uri, [], $this->headersFor($user, $asCafe));
    }

    protected function qrToken(Station $station): string
    {
        return app(StationTokenService::class)->token($station->id);
    }
}
