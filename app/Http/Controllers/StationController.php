<?php

namespace App\Http\Controllers;

use App\Http\Requests\StationRequest;
use App\Http\Resources\Present;
use App\Models\Booking;
use App\Models\GameSession;
use App\Models\Station;
use App\Services\AuditService;
use App\Services\BillingService;
use App\Services\StationTokenService;
use App\Support\Money;
use App\Support\Tenancy\CafeContext;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StationController extends Controller
{
    public function __construct(
        private readonly CafeContext $context,
        private readonly StationTokenService $qr,
        private readonly AuditService $audit,
        private readonly BillingService $billing,
    ) {}

    public function index(): JsonResponse
    {
        $stations = $this->context->scope(Station::class)->orderBy('id')->get();

        return response()->json($stations->map(Present::station(...))->all());
    }

    public function store(StationRequest $request): JsonResponse
    {
        $station = Station::create([
            'cafe_id' => $this->context->id(),
            'name' => $request->string('name')->value(),
            'type' => $request->string('type')->value(),
            'hourly_rate' => Money::str($request->input('hourly_rate')),
            'extra_controller_rate' => Money::str($request->input('extra_controller_rate', 0)),
            'max_controllers' => (int) $request->input('max_controllers', 4),
            'is_active' => $request->boolean('is_active', true),
            'maintenance' => $request->boolean('maintenance', false),
            'created_at' => now(),
        ]);

        $station->qr_code_url = $this->qr->checkinUrl($station->id);
        $station->save();

        $this->audit->log('station_create', 'station', $station->id, sprintf(
            'Created station %s at %s/hr',
            $station->name,
            Money::str($station->hourly_rate),
        ));

        return response()->json(Present::station($station), Response::HTTP_CREATED);
    }

    public function update(StationRequest $request, int $id): JsonResponse
    {
        $station = $this->context->find(Station::class, $id);

        $station->fill($request->safe()->only([
            'name', 'type', 'max_controllers', 'is_active', 'maintenance',
        ]));

        foreach (['hourly_rate', 'extra_controller_rate'] as $field) {
            if ($request->has($field)) {
                $station->{$field} = Money::str($request->input($field));
            }
        }

        $station->save();

        $this->audit->log('station_update', 'station', $station->id, sprintf(
            'Updated station %s (%s/hr)',
            $station->name,
            Money::str($station->hourly_rate),
        ));

        return response()->json(Present::station($station));
    }

    /**
     * A station with session history is RETIRED, not deleted — its past sessions
     * and invoices still need something to point at (§5.2).
     */
    public function destroy(int $id): Response
    {
        $station = $this->context->find(Station::class, $id);

        $hasHistory = GameSession::where('station_id', $station->id)->exists()
            || Booking::where('station_id', $station->id)->exists();

        if ($hasHistory) {
            $station->is_active = false;
            $station->save();

            $this->audit->log('station_delete', 'station', $station->id, sprintf(
                'Deactivated station %s (has history)',
                $station->name,
            ));
        } else {
            $name = $station->name;
            $station->delete();

            $this->audit->log('station_delete', 'station', $id, "Deleted station {$name}");
        }

        return response()->noContent();
    }

    public function maintenance(Request $request, int $id): JsonResponse
    {
        $station = $this->context->find(Station::class, $id);

        $station->maintenance = $request->boolean('maintenance', ! $station->maintenance);
        $station->save();

        $this->audit->log('station_maintenance', 'station', $station->id, sprintf(
            '%s marked %s',
            $station->name,
            $station->maintenance ? 'out of service' : 'back in service',
        ));

        return response()->json(Present::station($station));
    }

    /** Public: the PNG printed onto the booth's sticker. */
    public function qrcode(int $id): Response
    {
        $station = Station::find($id);

        if ($station === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $png = (new Builder(
            writer: new PngWriter,
            data: $this->qr->checkinUrl($station->id),
            size: 320,
            margin: 12,
        ))->build();

        return response($png->getString(), 200, [
            'Content-Type' => $png->getMimeType(),
            'Content-Disposition' => sprintf('inline; filename="station-%d.png"', $station->id),
        ]);
    }

    /**
     * Public: what the QR page reads.
     *
     * Returns the station plus its active session, and MUST NEVER leak the
     * customer's phone number — only their name (§6.3).
     */
    public function publicShow(int $id): JsonResponse
    {
        $station = Station::find($id);

        if ($station === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $session = GameSession::with('customer')
            ->where('station_id', $station->id)
            ->where('status', 'active')
            ->first();

        $activeSession = null;

        if ($session !== null) {
            $elapsed = $this->billing->actualMinutes($session->start_time, now());

            $activeSession = [
                'id' => $session->id,
                // Name only. No phone_or_id.
                'customer_name' => $session->customer?->name,
                'start_time' => Present::time($session->start_time),
                'elapsed_minutes' => $elapsed,
                'running_cost' => Money::str($this->billing->runningCost($session, now())),
                'controllers' => $session->controllers,
                'hourly_rate' => Money::str($session->hourly_rate_snapshot),
                'planned_minutes' => $session->planned_minutes,
            ];
        }

        return response()->json([
            'id' => $station->id,
            'name' => $station->name,
            'type' => $station->type,
            'hourly_rate' => Money::str($station->hourly_rate),
            'extra_controller_rate' => Money::str($station->extra_controller_rate),
            'max_controllers' => $station->max_controllers,
            'is_active' => (bool) $station->is_active,
            'maintenance' => (bool) $station->maintenance,
            'active_session' => $activeSession,
        ]);
    }
}
