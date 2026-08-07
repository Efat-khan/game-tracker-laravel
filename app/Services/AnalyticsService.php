<?php

namespace App\Services;

use App\Models\GameSession;
use App\Models\Invoice;
use App\Models\Shift;
use App\Models\Station;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * §6.4. Two rules hold across every query here: it is scoped to ONE cafe, and
 * void invoices are excluded everywhere.
 */
class AnalyticsService
{
    public function __construct(private readonly SettingsService $settings) {}

    private function since(int $days): CarbonImmutable
    {
        return CarbonImmutable::now()->subDays($days - 1)->startOfDay();
    }

    /** Non-void invoices only, at any payment status. */
    private function invoices(int $cafeId, int $days)
    {
        return Invoice::where('cafe_id', $cafeId)
            ->where('status', '!=', 'void')
            ->where('created_at', '>=', $this->since($days));
    }

    private function dec(mixed $value): BigDecimal
    {
        return Money::of(is_string($value) ? $value : (string) ($value ?? '0'));
    }

    /** Per day, including days with zero so the chart has no gaps. */
    public function dailyIncome(int $cafeId, int $days): array
    {
        $rows = $this->invoices($cafeId, $days)
            ->selectRaw('DATE(created_at) as d, SUM(total_amount) as income, SUM(duration_minutes) as minutes, COUNT(*) as sessions')
            ->groupBy('d')
            ->get()
            ->keyBy('d');

        $out = [];
        $cursor = $this->since($days);

        for ($i = 0; $i < $days; $i++) {
            $key = $cursor->format('Y-m-d');
            $row = $rows->get($key);

            $out[] = [
                'date' => $key,
                'income' => Money::str($this->dec($row->income ?? 0)),
                'hours' => (float) $this->dec((string) ($row->minutes ?? 0))
                    ->dividedBy(60, 2, RoundingMode::HalfUp)
                    ->__toString(),
                'sessions' => (int) ($row->sessions ?? 0),
            ];

            $cursor = $cursor->addDay();
        }

        return $out;
    }

    public function topStations(int $cafeId, int $days, int $limit): array
    {
        $rows = $this->invoices($cafeId, $days)
            ->join('sessions', 'sessions.id', '=', 'invoices.session_id')
            ->join('stations', 'stations.id', '=', 'sessions.station_id')
            ->selectRaw('stations.id as station_id, stations.name as station_name, SUM(invoices.total_amount) as revenue, COUNT(*) as sessions, SUM(invoices.duration_minutes) as minutes')
            ->groupBy('stations.id', 'stations.name')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($r) => [
            'station_id' => (int) $r->station_id,
            'station_name' => $r->station_name,
            'revenue' => Money::str($this->dec($r->revenue)),
            'sessions' => (int) $r->sessions,
            'hours' => (float) (string) $this->dec((string) $r->minutes)->dividedBy(60, 2, RoundingMode::HalfUp),
        ])->all();
    }

    public function topCustomers(int $cafeId, int $days, int $limit): array
    {
        $rows = $this->invoices($cafeId, $days)
            ->join('sessions', 'sessions.id', '=', 'invoices.session_id')
            ->join('customers', 'customers.id', '=', 'sessions.customer_id')
            ->selectRaw('customers.id as customer_id, customers.name as customer_name, SUM(invoices.total_amount) as spend, COUNT(*) as visits')
            ->groupBy('customers.id', 'customers.name')
            ->orderByDesc('spend')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($r) => [
            'customer_id' => (int) $r->customer_id,
            'customer_name' => $r->customer_name,
            'spend' => Money::str($this->dec($r->spend)),
            'visits' => (int) $r->visits,
        ])->all();
    }

    /**
     * A weekday × hour occupancy heatmap.
     *
     * Every session is walked hour by hour and the fraction of each hour it
     * covers is added to that bucket, so a 20-minute session adds 0.33 rather
     * than a whole hour. All 7 × 24 = 168 cells are returned, zeros included,
     * so the heatmap has no holes.
     */
    public function peakHours(int $cafeId, int $days): array
    {
        $since = $this->since($days);
        $now = CarbonImmutable::now();

        $sessions = GameSession::where('cafe_id', $cafeId)
            ->whereIn('status', ['active', 'completed'])
            ->where('start_time', '>=', $since)
            ->get(['start_time', 'end_time', 'status']);

        $buckets = [];

        for ($d = 0; $d < 7; $d++) {
            for ($h = 0; $h < 24; $h++) {
                $buckets[$d][$h] = 0.0;
            }
        }

        foreach ($sessions as $session) {
            $start = CarbonImmutable::instance($session->start_time);
            $end = $session->end_time !== null ? CarbonImmutable::instance($session->end_time) : $now;

            if ($end->lessThanOrEqualTo($start)) {
                continue;
            }

            $cursor = $start;

            while ($cursor->lessThan($end)) {
                $hourEnd = $cursor->addHour()->startOfHour();
                $sliceEnd = $hourEnd->greaterThan($end) ? $end : $hourEnd;

                $fraction = ($sliceEnd->getTimestamp() - $cursor->getTimestamp()) / 3600;

                if ($fraction > 0) {
                    $buckets[(int) $cursor->dayOfWeek][(int) $cursor->hour] += $fraction;
                }

                $cursor = $sliceEnd;
            }
        }

        $cells = [];

        for ($d = 0; $d < 7; $d++) {
            for ($h = 0; $h < 24; $h++) {
                $cells[] = [
                    // 0 = Sunday, matching Carbon and JavaScript's getDay().
                    'weekday' => $d,
                    'hour' => $h,
                    'hours' => round($buckets[$d][$h], 2),
                ];
            }
        }

        return $cells;
    }

    /**
     * Per-station share of TRADING hours — capacity is days × (close − open)
     * from the cafe's own hours, not 24 (§6.4). An active session counts up to
     * now.
     */
    public function utilization(int $cafeId, int $days): array
    {
        $hours = $this->settings->tradingHours($cafeId);
        $availablePerStation = $days * max(1, $hours['close'] - $hours['open']);

        $since = $this->since($days);
        $now = CarbonImmutable::now();

        $stations = Station::where('cafe_id', $cafeId)->orderBy('id')->get();

        $sessions = GameSession::where('cafe_id', $cafeId)
            ->whereIn('status', ['active', 'completed'])
            ->where('start_time', '>=', $since)
            ->get(['station_id', 'start_time', 'end_time']);

        $occupied = [];

        foreach ($sessions as $session) {
            $end = $session->end_time !== null
                ? CarbonImmutable::instance($session->end_time)
                : $now;

            $seconds = max(0, $end->getTimestamp() - $session->start_time->getTimestamp());

            $occupied[$session->station_id] = ($occupied[$session->station_id] ?? 0) + $seconds / 3600;
        }

        $revenue = $this->invoices($cafeId, $days)
            ->join('sessions', 'sessions.id', '=', 'invoices.session_id')
            ->selectRaw('sessions.station_id, SUM(invoices.total_amount) as revenue')
            ->groupBy('sessions.station_id')
            ->pluck('revenue', 'station_id');

        $rows = $stations->map(function (Station $station) use ($occupied, $availablePerStation, $revenue) {
            $used = $occupied[$station->id] ?? 0.0;

            return [
                'station_id' => $station->id,
                'station_name' => $station->name,
                'occupied_hours' => round($used, 2),
                'available_hours' => $availablePerStation,
                'utilization_percent' => round(min(100, $availablePerStation > 0 ? $used / $availablePerStation * 100 : 0), 1),
                'revenue' => Money::str($this->dec($revenue[$station->id] ?? '0')),
            ];
        })->sortByDesc('utilization_percent')->values()->all();

        return $rows;
    }

    /**
     * Revenue, cost of goods and gross margin.
     *
     * Reconciles both ways:
     *   play + items − discounts = total_revenue
     *   total_revenue − items_cost = gross_profit
     */
    public function profit(int $cafeId, int $days): array
    {
        $totals = $this->invoices($cafeId, $days)
            ->selectRaw('SUM(session_amount) as play, SUM(items_amount) as items, SUM(discount_amount) as discounts, SUM(total_amount) as total')
            ->first();

        $play = $this->dec($totals->play ?? 0);
        $items = $this->dec($totals->items ?? 0);
        $discounts = $this->dec($totals->discounts ?? 0);
        $total = $this->dec($totals->total ?? 0);

        $cost = $this->dec(
            DB::table('invoice_items')
                ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
                ->where('invoices.cafe_id', $cafeId)
                ->where('invoices.status', '!=', 'void')
                ->where('invoices.created_at', '>=', $this->since($days))
                ->selectRaw('SUM(invoice_items.unit_cost * invoice_items.quantity) as c')
                ->value('c')
        );

        $grossProfit = $total->minus($cost);

        $margin = $total->isPositive()
            ? (float) (string) $grossProfit->multipliedBy(100)->dividedBy($total, 1, RoundingMode::HalfUp)
            : 0.0;

        return [
            'play_revenue' => Money::str($play),
            'items_revenue' => Money::str($items),
            'discounts' => Money::str($discounts),
            'total_revenue' => Money::str($total),
            'items_cost' => Money::str($cost),
            'gross_profit' => Money::str($grossProfit),
            'margin_percent' => $margin,
        ];
    }

    /** Per staff email, from CLOSED shifts only — an open shift has no variance yet. */
    public function staff(int $cafeId, int $days): array
    {
        $shifts = Shift::where('cafe_id', $cafeId)
            ->where('status', 'closed')
            ->where('opened_at', '>=', $this->since($days))
            ->get();

        $byEmail = [];

        foreach ($shifts as $shift) {
            $email = $shift->opened_by_email;

            $byEmail[$email] ??= [
                'email' => $email,
                'shifts' => 0,
                'total_sales' => Money::zero(),
                'cash_collected' => Money::zero(),
                'total_variance' => Money::zero(),
            ];

            $totals = app(ShiftService::class)->totals($shift);

            $byEmail[$email]['shifts']++;
            $byEmail[$email]['total_sales'] = $byEmail[$email]['total_sales']->plus(Money::of($totals['total_sales']));
            $byEmail[$email]['cash_collected'] = $byEmail[$email]['cash_collected']->plus(Money::of($totals['cash_sales']));
            $byEmail[$email]['total_variance'] = $byEmail[$email]['total_variance']->plus(Money::of($shift->variance ?? 0));
        }

        return array_values(array_map(fn (array $row) => [
            'email' => $row['email'],
            'shifts' => $row['shifts'],
            'total_sales' => Money::str($row['total_sales']),
            'cash_collected' => Money::str($row['cash_collected']),
            'total_variance' => Money::str($row['total_variance']),
        ], $byEmail));
    }
}
