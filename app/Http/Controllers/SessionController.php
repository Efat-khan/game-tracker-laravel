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
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class SessionController extends Controller
{
    /** What a hand-entered discount is called when nobody typed a reason. */
    private const UNNAMED_DISCOUNT = 'Counter discount';

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

    /**
     * What this session bills if it ends now — the confirm screen reads it so
     * the operator can tell the player what to pay before committing.
     */
    public function quote(Request $request, int $id): JsonResponse
    {
        $session = $this->context->find(GameSession::class, $id);

        if ($session->status !== 'active') {
            return response()->json(['message' => 'This session has already ended.'], 409);
        }

        return response()->json($this->sessions->quote($session, $this->manualDiscount($request)));
    }

    /**
     * A discount typed in at the counter, or null when none was asked for.
     *
     * Admin only, matching the discount action on an invoice — money given
     * away is not a floor decision. Both the preview and the checkout go
     * through here so they cannot disagree about who may do it.
     */
    private function manualDiscount(Request $request): ?array
    {
        $hasAmount = $request->filled('discount_amount');
        $hasPercent = $request->filled('discount_percent');

        if (! $hasAmount && ! $hasPercent) {
            return null;
        }

        if (! $this->context->isAdmin()) {
            throw new AccessDeniedHttpException('Only an admin can discount a bill.');
        }

        $request->validate([
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // Optional on purpose. The figure is what the operator is reading
            // out to a waiting customer, so typing it must move the total at
            // once; demanding the reason first meant the amount silently did
            // nothing. A blank one is recorded as a plain counter discount,
            // which is still a discount named in the log and on the invoice.
            'discount_reason' => ['nullable', 'string', 'max:200'],
        ]);

        if ($hasAmount && $hasPercent) {
            throw ValidationException::withMessages([
                'discount_amount' => 'Give an amount or a percentage, not both.',
            ]);
        }

        $reason = trim((string) $request->input('discount_reason', ''));

        return [
            'amount' => $hasAmount ? (string) $request->input('discount_amount') : null,
            'percent' => $hasPercent ? (string) $request->input('discount_percent') : null,
            'reason' => $reason === '' ? self::UNNAMED_DISCOUNT : $reason,
        ];
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

        $invoice = $this->sessions->checkOut($session, $method, $this->manualDiscount($request));

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
