<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Cafe;
use App\Models\Product;
use App\Models\Station;
use App\Services\SettingsService;
use App\Support\Money;
use Tests\TestCase;

/** §6.4 — the reports the owner makes decisions on. */
class AnalyticsTest extends TestCase
{
    private Cafe $cafe;

    private AdminUser $admin;

    private Station $station;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cafe = $this->makeCafe();
        $this->admin = $this->makeUser($this->cafe, 'admin', 'admin@example.com');
        $this->station = $this->makeStation($this->cafe);

        app(SettingsService::class)->put($this->cafe->id, [
            'billing_round_minutes' => 1, 'round_amount_to' => 1,
        ]);

        $this->product = Product::create([
            'cafe_id' => $this->cafe->id, 'name' => 'Nachos', 'category' => 'Snacks',
            'price' => '150.00', 'cost_price' => '88.00', 'created_at' => now(),
        ]);
    }

    /** 60 min at 150/hr = 150.00. */
    private function invoice(bool $paid = true): array
    {
        $customer = $this->makeCustomer($this->cafe, ['phone_or_id' => '017'.mt_rand(10000000, 99999999)]);
        $session = $this->makeSession($this->cafe, $this->station, $customer, 60);

        $invoice = $this->apiPost($this->admin, "/api/checkout/{$session->id}")->assertCreated()->json();

        if ($paid) {
            $this->apiPatch($this->admin, "/api/invoices/{$invoice['id']}", [
                'payment_status' => 'paid', 'payment_method' => 'cash',
            ])->assertOk();
        }

        return $invoice;
    }

    /* ------------------------------------------------------------- profit */

    public function test_profit_reconciles_both_ways(): void
    {
        $invoice = $this->invoice();

        $this->apiPost($this->admin, "/api/invoices/{$invoice['id']}/items", [
            'product_id' => $this->product->id, 'quantity' => 2,
        ])->assertCreated();

        $this->apiPost($this->admin, "/api/invoices/{$invoice['id']}/discount", [
            'amount' => '50', 'reason' => 'regular customer',
        ])->assertOk();

        $p = $this->apiGet($this->admin, '/api/analytics/profit')->json();

        // play + items - discounts = total_revenue
        $this->assertSame(
            Money::str(Money::of($p['play_revenue'])->plus(Money::of($p['items_revenue']))->minus(Money::of($p['discounts']))),
            $p['total_revenue'],
        );

        // total_revenue - items_cost = gross_profit
        $this->assertSame(
            Money::str(Money::of($p['total_revenue'])->minus(Money::of($p['items_cost']))),
            $p['gross_profit'],
        );

        $this->assertSame('150.00', $p['play_revenue']);
        $this->assertSame('300.00', $p['items_revenue']);
        $this->assertSame('50.00', $p['discounts']);
        $this->assertSame('400.00', $p['total_revenue']);
        // 2 x 88.00 cost
        $this->assertSame('176.00', $p['items_cost']);
        $this->assertSame('224.00', $p['gross_profit']);
    }

    public function test_profit_excludes_voided_invoices(): void
    {
        $this->invoice();
        $doomed = $this->invoice();

        $this->apiPost($this->admin, "/api/invoices/{$doomed['id']}/void", ['reason' => 'wrong booth'])->assertOk();

        $p = $this->apiGet($this->admin, '/api/analytics/profit')->json();

        $this->assertSame('150.00', $p['total_revenue']);
    }

    public function test_margin_percent_is_reported(): void
    {
        $invoice = $this->invoice();

        $this->apiPost($this->admin, "/api/invoices/{$invoice['id']}/items", [
            'product_id' => $this->product->id, 'quantity' => 1,
        ])->assertCreated();

        $p = $this->apiGet($this->admin, '/api/analytics/profit')->json();

        // (300 - 88) / 300 = 70.7%
        $this->assertSame('300.00', $p['total_revenue']);
        $this->assertEquals(70.7, $p['margin_percent']);
    }

    public function test_profit_on_an_empty_cafe_is_all_zeros(): void
    {
        $p = $this->apiGet($this->admin, '/api/analytics/profit')->json();

        $this->assertSame('0.00', $p['total_revenue']);
        $this->assertSame('0.00', $p['gross_profit']);
        $this->assertEquals(0.0, $p['margin_percent']);
    }

    /* ------------------------------------------------------- daily income */

    public function test_daily_income_includes_days_with_no_trade(): void
    {
        $this->invoice();

        $days = $this->apiGet($this->admin, '/api/analytics/daily-income?days=7')->json();

        $this->assertCount(7, $days);

        $today = collect($days)->firstWhere('date', now()->format('Y-m-d'));
        $this->assertSame('150.00', $today['income']);
        $this->assertEquals(1.0, $today['hours']);

        // The six quiet days before it are present, at zero.
        $quiet = collect($days)->where('date', '!=', now()->format('Y-m-d'));
        $this->assertCount(6, $quiet);
        $this->assertTrue($quiet->every(fn ($d) => $d['income'] === '0.00' && $d['sessions'] === 0));
    }

    public function test_daily_income_excludes_voided_invoices(): void
    {
        $doomed = $this->invoice();
        $this->apiPost($this->admin, "/api/invoices/{$doomed['id']}/void", ['reason' => 'wrong booth'])->assertOk();

        $today = collect($this->apiGet($this->admin, '/api/analytics/daily-income?days=7')->json())
            ->firstWhere('date', now()->format('Y-m-d'));

        $this->assertSame('0.00', $today['income']);
    }

    /* ------------------------------------------------------------ rankings */

    public function test_top_stations_ranks_by_revenue_and_excludes_voids(): void
    {
        $second = $this->makeStation($this->cafe, ['name' => 'PS5 - Booth 2', 'hourly_rate' => '300.00']);

        $this->invoice();

        $customer = $this->makeCustomer($this->cafe, ['phone_or_id' => '01755555555']);
        $session = $this->makeSession($this->cafe, $second, $customer, 60);
        $this->apiPost($this->admin, "/api/checkout/{$session->id}")->assertCreated();

        $rows = $this->apiGet($this->admin, '/api/analytics/top-stations')->json();

        $this->assertSame('PS5 - Booth 2', $rows[0]['station_name']);
        $this->assertSame('300.00', $rows[0]['revenue']);
        $this->assertSame('150.00', $rows[1]['revenue']);
    }

    public function test_top_customers_ranks_by_spend(): void
    {
        $customer = $this->makeCustomer($this->cafe, ['name' => 'Big Spender', 'phone_or_id' => '01766666666']);

        foreach ([60, 60, 60] as $minutes) {
            $session = $this->makeSession($this->cafe, $this->station, $customer, $minutes);
            $this->apiPost($this->admin, "/api/checkout/{$session->id}")->assertCreated();
        }

        $this->invoice();

        $rows = $this->apiGet($this->admin, '/api/analytics/top-customers')->json();

        $this->assertSame('Big Spender', $rows[0]['customer_name']);
        $this->assertSame('450.00', $rows[0]['spend']);
        $this->assertSame(3, $rows[0]['visits']);
    }

    /* --------------------------------------------------------- utilization */

    public function test_utilization_is_measured_against_trading_hours_not_24h(): void
    {
        // 10:00-23:00 is 13 trading hours a day.
        $this->apiPatch($this->admin, '/api/settings', ['open_hour' => 10, 'close_hour' => 23])->assertOk();

        $this->invoice(); // one 60-minute session

        $rows = $this->apiGet($this->admin, '/api/analytics/utilization?days=1')->json();

        $row = collect($rows)->firstWhere('station_id', $this->station->id);

        $this->assertEquals(13, $row['available_hours']);
        $this->assertEquals(1.0, $row['occupied_hours']);
        // 1/13 = 7.7%, not 1/24 = 4.2%
        $this->assertEquals(7.7, $row['utilization_percent']);
    }

    public function test_utilization_follows_a_change_to_trading_hours(): void
    {
        $this->apiPatch($this->admin, '/api/settings', ['open_hour' => 12, 'close_hour' => 18])->assertOk();

        $this->invoice();

        $row = collect($this->apiGet($this->admin, '/api/analytics/utilization?days=1')->json())
            ->firstWhere('station_id', $this->station->id);

        $this->assertEquals(6, $row['available_hours']);
        $this->assertEquals(16.7, $row['utilization_percent']);
    }

    public function test_utilization_falls_back_when_closing_does_not_exceed_opening(): void
    {
        // Nonsense hours would make the denominator zero or negative.
        $this->apiPatch($this->admin, '/api/settings', ['open_hour' => 20, 'close_hour' => 8])->assertOk();

        $row = collect($this->apiGet($this->admin, '/api/analytics/utilization?days=1')->json())
            ->firstWhere('station_id', $this->station->id);

        // Falls back to 10/23.
        $this->assertEquals(13, $row['available_hours']);
    }

    public function test_utilization_never_exceeds_one_hundred_percent(): void
    {
        $this->apiPatch($this->admin, '/api/settings', ['open_hour' => 10, 'close_hour' => 11])->assertOk();

        // Five hours of play against a one-hour trading window.
        for ($i = 0; $i < 5; $i++) {
            $customer = $this->makeCustomer($this->cafe, ['phone_or_id' => '017'.mt_rand(10000000, 99999999)]);
            $session = $this->makeSession($this->cafe, $this->station, $customer, 60);
            $this->apiPost($this->admin, "/api/checkout/{$session->id}")->assertCreated();
        }

        $row = collect($this->apiGet($this->admin, '/api/analytics/utilization?days=1')->json())
            ->firstWhere('station_id', $this->station->id);

        $this->assertEquals(100.0, $row['utilization_percent']);
    }

    /* ---------------------------------------------------------- peak hours */

    public function test_the_heatmap_returns_all_one_hundred_and_sixty_eight_cells(): void
    {
        $cells = $this->apiGet($this->admin, '/api/analytics/peak-hours')->json();

        $this->assertCount(168, $cells);

        // Every (weekday, hour) pair present exactly once — no holes.
        $pairs = collect($cells)->map(fn ($c) => $c['weekday'].':'.$c['hour'])->unique();
        $this->assertCount(168, $pairs);
    }

    public function test_the_heatmap_buckets_by_weekday_and_hour(): void
    {
        $customer = $this->makeCustomer($this->cafe);

        // A session that ran for exactly one hour, wholly inside one clock hour.
        $start = now()->subDays(2)->setTime(14, 0);

        \App\Models\GameSession::create([
            'cafe_id' => $this->cafe->id,
            'station_id' => $this->station->id,
            'customer_id' => $customer->id,
            'start_time' => $start,
            'end_time' => $start->copy()->addHour(),
            'status' => 'completed',
            'hourly_rate_snapshot' => '150.00',
            'base_rate_snapshot' => '150.00',
            'extra_controller_rate_snapshot' => '50.00',
            'controllers' => 1,
            'created_at' => $start,
        ]);

        $cells = collect($this->apiGet($this->admin, '/api/analytics/peak-hours?days=7')->json());

        $cell = $cells->first(fn ($c) => $c['weekday'] === (int) $start->dayOfWeek && $c['hour'] === 14);

        $this->assertEquals(1.0, $cell['hours']);

        // Everything else stays empty.
        $this->assertEquals(1.0, round($cells->sum('hours'), 2));
    }

    public function test_the_heatmap_splits_a_session_across_the_hours_it_spans(): void
    {
        $customer = $this->makeCustomer($this->cafe);

        // 14:30 to 15:30 — half an hour in each bucket.
        $start = now()->subDays(2)->setTime(14, 30);

        \App\Models\GameSession::create([
            'cafe_id' => $this->cafe->id,
            'station_id' => $this->station->id,
            'customer_id' => $customer->id,
            'start_time' => $start,
            'end_time' => $start->copy()->addHour(),
            'status' => 'completed',
            'hourly_rate_snapshot' => '150.00',
            'base_rate_snapshot' => '150.00',
            'extra_controller_rate_snapshot' => '50.00',
            'controllers' => 1,
            'created_at' => $start,
        ]);

        $cells = collect($this->apiGet($this->admin, '/api/analytics/peak-hours?days=7')->json());
        $weekday = (int) $start->dayOfWeek;

        $this->assertEquals(0.5, $cells->first(fn ($c) => $c['weekday'] === $weekday && $c['hour'] === 14)['hours']);
        $this->assertEquals(0.5, $cells->first(fn ($c) => $c['weekday'] === $weekday && $c['hour'] === 15)['hours']);
    }

    /* -------------------------------------------------------------- scoping */

    public function test_two_cafes_revenue_never_mixes(): void
    {
        $other = $this->makeCafe('Other Cafe');
        $otherAdmin = $this->makeUser($other, 'admin', 'other@example.com');
        $otherStation = $this->makeStation($other);

        app(SettingsService::class)->put($other->id, [
            'billing_round_minutes' => 1, 'round_amount_to' => 1,
        ]);

        $this->invoice();

        $customer = $this->makeCustomer($other);
        $session = $this->makeSession($other, $otherStation, $customer, 120);
        $this->apiPost($otherAdmin, "/api/checkout/{$session->id}")->assertCreated();

        $this->assertSame('150.00', $this->apiGet($this->admin, '/api/analytics/profit')->json('total_revenue'));
        $this->assertSame('300.00', $this->apiGet($otherAdmin, '/api/analytics/profit')->json('total_revenue'));

        // And the rankings stay separate too.
        $this->assertCount(1, $this->apiGet($this->admin, '/api/analytics/top-stations')->json());
        $this->assertCount(1, $this->apiGet($otherAdmin, '/api/analytics/top-stations')->json());
    }

    public function test_the_days_parameter_is_clamped(): void
    {
        // 1..365; anything outside is pulled back into range rather than erroring.
        $this->assertCount(1, $this->apiGet($this->admin, '/api/analytics/daily-income?days=0')->json());
        $this->assertCount(365, $this->apiGet($this->admin, '/api/analytics/daily-income?days=9999')->json());
    }
}
