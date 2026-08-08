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

    /**
     * Non-void invoices only, at any payment status.
     *
     * Every column is table-qualified: several of these queries join `sessions`,
     * which carries its own cafe_id, status and created_at, and an unqualified
     * name would be ambiguous.
     */
    private function invoices(int $cafeId, int $days)
    {
        return Invoice::where('invoices.cafe_id', $cafeId)
            ->where('invoices.status', '!=', 'void')
            ->where('invoices.created_at', '>=', $this->since($days));
    }

    private function dec(mixed $value): BigDecimal
    {
        return Money::of(is_string($value) ? $value : (string) ($value ?? '0'));
    }

    /** Per day, including days with zero so the chart has no gaps. */
    public function dailyIncome(int $cafeId, int $days): array
    {
        $rows = $this->invoices($cafeId, $days)
            ->selectRaw('DATE(invoices.created_at) as d, SUM(invoices.total_amount) as income, SUM(invoices.duration_minutes) as minutes, COUNT(*) as sessions')
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
            ->selectRaw('SUM(invoices.session_amount) as play, SUM(invoices.items_amount) as items, SUM(invoices.discount_amount) as discounts, SUM(invoices.total_amount) as total')
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

    /* ------------------------------------------------------- summary sheets */

    /**
     * One trading day, laid out the way a cafe reads it at closing.
     *
     * Deliberately a different shape from the charts above: a table you settle
     * up against, not a trend. It answers "what did each kind of device take,
     * what went out of the drawer, and what is left".
     *
     * `expenses` are the cash paid OUT of the drawer during the day, each with
     * the reason it was recorded under. That is the only outgoing the system
     * holds — money that never passed through the till is not in here.
     */
    public function dailySummary(int $cafeId, string $date): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();

        return $this->summaryFor($cafeId, $day, $day->addDay()) + [
            'date' => $day->format('Y-m-d'),
            'expenses' => $this->movements($cafeId, $day, $day->addDay()),
        ];
    }

    /**
     * A month, one row per day, with the device breakdown for the whole month
     * underneath.
     *
     * Every day of the month is present, including the ones with no trade —
     * a gap in a ledger reads as missing data rather than a quiet Tuesday.
     */
    public function monthlySummary(int $cafeId, string $month): array
    {
        $start = CarbonImmutable::parse($month.'-01')->startOfMonth();
        $end = $start->addMonth();

        $income = $this->invoicesBetween($cafeId, $start, $end)
            ->selectRaw('DATE(invoices.created_at) as d, COUNT(*) as sessions, SUM(invoices.duration_minutes) as minutes, SUM(invoices.total_amount) as income')
            ->groupBy('d')
            ->get()
            ->keyBy('d');

        $spend = DB::table('cash_movements')
            ->join('shifts', 'shifts.id', '=', 'cash_movements.shift_id')
            ->where('shifts.cafe_id', $cafeId)
            ->where('cash_movements.kind', 'out')
            ->where('cash_movements.created_at', '>=', $start)
            ->where('cash_movements.created_at', '<', $end)
            ->selectRaw('DATE(cash_movements.created_at) as d, SUM(cash_movements.amount) as spent')
            ->groupBy('d')
            ->pluck('spent', 'd');

        $days = [];

        for ($cursor = $start; $cursor < $end; $cursor = $cursor->addDay()) {
            $key = $cursor->format('Y-m-d');
            $row = $income->get($key);

            $earned = $this->dec($row->income ?? 0);
            $spent = $this->dec($spend[$key] ?? 0);

            $days[] = [
                'date' => $key,
                'sessions' => (int) ($row->sessions ?? 0),
                'hours' => $this->hours($row->minutes ?? 0),
                'income' => Money::str($earned),
                'expenses' => Money::str($spent),
                'net' => Money::str($earned->minus($spent)),
            ];
        }

        return $this->summaryFor($cafeId, $start, $end) + [
            'month' => $start->format('Y-m'),
            'days' => $days,
        ];
    }

    /**
     * The half both sheets share: takings by device, how the money arrived,
     * what went out, and what is left.
     */
    private function summaryFor(int $cafeId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byDevice = $this->invoicesBetween($cafeId, $from, $to)
            ->join('sessions', 'sessions.id', '=', 'invoices.session_id')
            ->join('stations', 'stations.id', '=', 'sessions.station_id')
            ->selectRaw('stations.type as device, COUNT(*) as sessions, SUM(invoices.duration_minutes) as minutes, SUM(invoices.total_amount) as income')
            ->groupBy('stations.type')
            ->orderBy('stations.type')
            ->get()
            ->map(fn ($r) => [
                'device' => $r->device,
                'sessions' => (int) $r->sessions,
                'hours' => $this->hours($r->minutes),
                'income' => Money::str($this->dec($r->income)),
            ])
            ->all();

        $paid = $this->invoicesBetween($cafeId, $from, $to)->where('invoices.payment_status', 'paid');

        $cash = $this->dec((clone $paid)->where('invoices.payment_method', 'cash')->sum('invoices.total_amount'));
        $phone = $this->dec((clone $paid)->where('invoices.payment_method', 'phone_payment')->sum('invoices.total_amount'));
        $wallet = $this->dec((clone $paid)->where('invoices.payment_method', 'wallet')->sum('invoices.total_amount'));

        $unpaid = $this->dec(
            $this->invoicesBetween($cafeId, $from, $to)
                ->where('invoices.payment_status', 'unpaid')
                ->sum('invoices.total_amount')
        );

        $income = $this->dec($this->invoicesBetween($cafeId, $from, $to)->sum('invoices.total_amount'));
        $minutes = (int) $this->invoicesBetween($cafeId, $from, $to)->sum('invoices.duration_minutes');
        $sessions = (int) $this->invoicesBetween($cafeId, $from, $to)->count();

        $out = $this->dec($this->movementTotal($cafeId, $from, $to, 'out'));
        $in = $this->dec($this->movementTotal($cafeId, $from, $to, 'in'));

        return [
            'devices' => $byDevice,
            'totals' => [
                'sessions' => $sessions,
                'hours' => $this->hours($minutes),
                // Every non-void invoice, whether it has been settled or not.
                'income' => Money::str($income),
                'expenses' => Money::str($out),
                'cash_in' => Money::str($in),
                'net' => Money::str($income->minus($out)),
                'cash_sales' => Money::str($cash),
                'phone_sales' => Money::str($phone),
                'wallet_sales' => Money::str($wallet),
                'unpaid' => Money::str($unpaid),
            ],
        ];
    }

    /** Non-void invoices in a half-open window: [from, to). */
    private function invoicesBetween(int $cafeId, CarbonImmutable $from, CarbonImmutable $to)
    {
        return Invoice::where('invoices.cafe_id', $cafeId)
            ->where('invoices.status', '!=', 'void')
            ->where('invoices.created_at', '>=', $from)
            ->where('invoices.created_at', '<', $to);
    }

    /** Cash that left or entered the drawer, itemised. */
    private function movements(int $cafeId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('cash_movements')
            ->join('shifts', 'shifts.id', '=', 'cash_movements.shift_id')
            ->where('shifts.cafe_id', $cafeId)
            ->where('cash_movements.created_at', '>=', $from)
            ->where('cash_movements.created_at', '<', $to)
            ->orderBy('cash_movements.created_at')
            ->get([
                'cash_movements.id',
                'cash_movements.kind',
                'cash_movements.amount',
                'cash_movements.reason',
                'cash_movements.actor_email',
                'cash_movements.created_at',
            ])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'kind' => $r->kind,
                'amount' => Money::str($this->dec($r->amount)),
                'reason' => $r->reason,
                'actor_email' => $r->actor_email,
                'created_at' => CarbonImmutable::parse($r->created_at)->format('Y-m-d\\TH:i:s'),
            ])
            ->all();
    }

    private function movementTotal(int $cafeId, CarbonImmutable $from, CarbonImmutable $to, string $kind): string
    {
        return (string) DB::table('cash_movements')
            ->join('shifts', 'shifts.id', '=', 'cash_movements.shift_id')
            ->where('shifts.cafe_id', $cafeId)
            ->where('cash_movements.kind', $kind)
            ->where('cash_movements.created_at', '>=', $from)
            ->where('cash_movements.created_at', '<', $to)
            ->sum('cash_movements.amount');
    }

    /** Minutes as decimal hours, to two places. */
    private function hours(mixed $minutes): float
    {
        return (float) (string) $this->dec((string) ($minutes ?? 0))->dividedBy(60, 2, RoundingMode::HalfUp);
    }
}
