<?php

namespace App\Http\Controllers;

use App\Http\Resources\Present;
use App\Models\GameSession;
use App\Models\Station;
use App\Services\BillingService;
use App\Services\SessionService;
use App\Support\Money;
use App\Support\Tenancy\CafeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SessionController extends Controller
{
    public function __construct(
        private readonly CafeContext $context,
        private readonly SessionService $sessions,
        private readonly BillingService $billing,
    ) {}

    /**
     * The dashboard: ONE ROW PER STATION, including free ones, so the grid can
     * render straight from this without stitching two lists together (§6.3).
     */
    public function active(): JsonResponse
    {
        // with('rates') so the price list does not cost one query per
        // station — the dashboard re-reads this list every five seconds.
        $stations = $this->context->scope(Station::class)->with('rates')->orderBy('id')->get();

        $sessions = GameSession::with('customer')
            ->whereIn('station_id', $stations->pluck('id'))
            ->where('status', 'active')
            ->get()
            ->keyBy('station_id');

        $now = now();

        $rows = $stations->map(function (Station $station) use ($sessions, $now) {
            $session = $sessions->get($station->id);

            $row = [
                'station_id' => $station->id,
                'station_name' => $station->name,
                'station_type' => $station->type,
                'hourly_rate' => Money::str($station->hourly_rate),
                'rates' => $station->rateMap(),
                'max_controllers' => $station->max_controllers,
                'is_active' => (bool) $station->is_active,
                'maintenance' => (bool) $station->maintenance,
                'status' => $station->maintenance ? 'maintenance' : ($session ? 'occupied' : 'free'),
                'session_id' => null,
                'customer_name' => null,
                'start_time' => null,
                'elapsed_minutes' => null,
                'running_cost' => null,
                'controllers' => null,
                'session_hourly_rate' => null,
                'planned_minutes' => null,
                'overdue_minutes' => null,
            ];

            if ($session === null) {
                return $row;
            }

            $elapsed = $this->billing->actualMinutes($session->start_time, $now);

            return array_merge($row, [
                'session_id' => $session->id,
                'customer_name' => $session->customer?->name,
                'start_time' => Present::time($session->start_time),
                'elapsed_minutes' => $elapsed,
                // Live cost: exact minutes, neither rounding step applied (§5.1).
                'running_cost' => Money::str($this->billing->runningCost($session, $now)),
                'controllers' => $session->controllers,
                'session_hourly_rate' => Money::str($session->hourly_rate_snapshot),
                'planned_minutes' => $session->planned_minutes,
                'overdue_minutes' => $session->planned_minutes === null
                    ? null
                    : max(0, $elapsed - $session->planned_minutes),
            ]);
        });

        return response()->json($rows->values()->all());
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->context->scope(GameSession::class)->with(['station', 'customer', 'invoice']);

        if ($request->filled('station_id')) {
            $query->where('station_id', (int) $request->query('station_id'));
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', (int) $request->query('customer_id'));
        }

        if (in_array($request->query('status'), ['active', 'completed', 'cancelled'], true)) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('date_from')) {
            $query->where('start_time', '>=', $request->date('date_from')->startOfDay());
        }

        if ($request->filled('date_to')) {
            $query->where('start_time', '<=', $request->date('date_to')->endOfDay());
        }

        $limit = min(1000, max(1, (int) $request->query('limit', 200)));

        $sessions = $query->orderByDesc('id')->limit($limit)->get();

        return response()->json($sessions->map(Present::session(...))->all());
    }

    /** Ending a session creates exactly one invoice. */
    public function checkout(Request $request, int $sessionId): JsonResponse
    {
        $session = $this->context->find(GameSession::class, $sessionId);

        $method = $request->input('payment_method');

        // wallet is never accepted here; it is set by pay-wallet (§6.2).
        if ($method !== null && ! in_array($method, ['cash', 'phone_payment'], true)) {
            return response()->json([
                'message' => 'The payment method must be cash or phone_payment.',
                'errors' => ['payment_method' => ['The payment method must be cash or phone_payment.']],
            ], 422);
        }

        $invoice = $this->sessions->checkOut($session, $method);

        return response()->json(
            Present::invoice($invoice->load(['items', 'session.station', 'session.customer'])),
            Response::HTTP_CREATED,
        );
    }

    /** A mistaken start: frees the device, bills nothing. */
    public function cancel(int $sessionId): JsonResponse
    {
        $session = $this->context->find(GameSession::class, $sessionId);

        $this->sessions->cancel($session);

        return response()->json(Present::session($session->load(['station', 'customer'])));
    }
}
