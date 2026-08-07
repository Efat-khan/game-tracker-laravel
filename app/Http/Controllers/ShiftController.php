<?php

namespace App\Http\Controllers;

use App\Http\Requests\CashMovementRequest;
use App\Http\Requests\CloseShiftRequest;
use App\Http\Requests\OpenShiftRequest;
use App\Http\Resources\Present;
use App\Models\CashMovement;
use App\Models\Shift;
use App\Services\AuditService;
use App\Services\ShiftService;
use App\Support\Money;
use App\Support\Tenancy\CafeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ShiftController extends Controller
{
    public function __construct(
        private readonly CafeContext $context,
        private readonly ShiftService $shifts,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $limit = min(365, max(1, (int) $request->query('limit', 50)));

        $shifts = $this->context->scope(Shift::class)->orderByDesc('id')->limit($limit)->get();

        return response()->json($shifts->map(fn (Shift $s) => Present::shift($s))->all());
    }

    public function current(): JsonResponse
    {
        $shift = $this->shifts->current($this->context->id());

        if ($shift === null) {
            return response()->json(null);
        }

        return response()->json($this->withTotals($shift));
    }

    public function show(int $id): JsonResponse
    {
        $shift = $this->context->find(Shift::class, $id);

        $movements = CashMovement::where('shift_id', $shift->id)->orderBy('id')->get()->map(fn ($m) => [
            'id' => $m->id,
            'kind' => $m->kind,
            'amount' => Money::str($m->amount),
            'reason' => $m->reason,
            'actor_email' => $m->actor_email,
            'created_at' => Present::time($m->created_at),
        ])->all();

        return response()->json(array_merge($this->withTotals($shift), ['movements' => $movements]));
    }

    private function withTotals(Shift $shift): array
    {
        return Present::shift(
            $shift,
            $this->shifts->totals($shift),
            Money::str($this->shifts->expectedCash($shift)),
        );
    }

    /** One open shift per cafe at a time. */
    public function open(OpenShiftRequest $request): JsonResponse
    {
        $cafeId = $this->context->id();

        if ($this->shifts->current($cafeId) !== null) {
            return response()->json(['message' => 'A shift is already open.'], 409);
        }

        $actor = $this->context->actor();

        $shift = Shift::create([
            'cafe_id' => $cafeId,
            'opened_by_id' => $actor?->id,
            'opened_by_email' => $this->context->actorEmail(),
            'opened_at' => now(),
            'opening_float' => Money::str($request->input('opening_float', 0)),
            'open_note' => (string) $request->string('note', ''),
            'status' => 'open',
        ]);

        $this->audit->log('shift_open', 'shift', $shift->id, sprintf(
            'Opened shift #%d with %s float',
            $shift->id,
            Money::str($shift->opening_float),
        ));

        return response()->json($this->withTotals($shift), Response::HTTP_CREATED);
    }

    /**
     * Reconcile and close.
     *
     * expected_cash and variance are FROZEN onto the row here: a void the next
     * day must not rewrite a reconciliation someone has already signed off
     * (§5.4).
     */
    public function close(CloseShiftRequest $request, int $id): JsonResponse
    {
        $shift = $this->context->find(Shift::class, $id);

        if ($shift->isClosed()) {
            return response()->json(['message' => 'This shift is already closed.'], 409);
        }

        $expected = $this->shifts->expectedCash($shift);
        $counted = Money::of($request->input('counted_cash'));

        $shift->counted_cash = (string) Money::round($counted);
        $shift->expected_cash = (string) $expected;
        $shift->variance = (string) Money::round($counted->minus($expected));
        $shift->closed_at = now();
        $shift->closed_by_id = $this->context->actorId();
        $shift->closed_by_email = $this->context->actorEmail();
        $shift->close_note = (string) $request->string('note', '');
        $shift->status = 'closed';
        $shift->save();

        $this->audit->log('shift_close', 'shift', $shift->id, sprintf(
            'Closed shift #%d — expected %s, counted %s, variance %s',
            $shift->id,
            Money::str($shift->expected_cash),
            Money::str($shift->counted_cash),
            Money::str($shift->variance),
        ));

        return response()->json($this->withTotals($shift->fresh()));
    }

    public function cash(CashMovementRequest $request, int $id): JsonResponse
    {
        $shift = $this->context->find(Shift::class, $id);

        if ($shift->isClosed()) {
            return response()->json(['message' => 'This shift is already closed.'], 409);
        }

        $movement = CashMovement::create([
            'shift_id' => $shift->id,
            'kind' => $request->string('kind')->value(),
            'amount' => Money::str($request->input('amount')),
            'reason' => $request->string('reason')->value(),
            'actor_email' => $this->context->actorEmail(),
            'created_at' => now(),
        ]);

        $this->audit->log(
            $movement->kind === 'in' ? 'cash_in' : 'cash_out',
            'shift',
            $shift->id,
            sprintf('Cash %s %s — %s', $movement->kind, Money::str($movement->amount), $movement->reason),
        );

        return response()->json([
            'movement' => [
                'id' => $movement->id,
                'kind' => $movement->kind,
                'amount' => Money::str($movement->amount),
                'reason' => $movement->reason,
                'actor_email' => $movement->actor_email,
                'created_at' => Present::time($movement->created_at),
            ],
            'shift' => $this->withTotals($shift->fresh()),
        ], Response::HTTP_CREATED);
    }
}
