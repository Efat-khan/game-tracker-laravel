<?php

namespace App\Http\Controllers;

use App\Http\Requests\BookingRequest;
use App\Http\Resources\Present;
use App\Models\Booking;
use App\Models\Station;
use App\Services\AuditService;
use App\Services\SessionService;
use App\Support\Tenancy\CafeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BookingController extends Controller
{
    public function __construct(
        private readonly CafeContext $context,
        private readonly SessionService $sessions,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->context->scope(Booking::class)->with('station');

        if ($request->filled('on')) {
            $day = $request->date('on');
            $query->whereBetween('starts_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()]);
        }

        if (in_array($request->query('status'), ['booked', 'arrived', 'cancelled', 'no_show'], true)) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('upcoming_hours')) {
            $hours = min(168, max(1, (int) $request->query('upcoming_hours')));
            $query->whereBetween('starts_at', [now(), now()->addHours($hours)]);
        }

        $limit = min(1000, max(1, (int) $request->query('limit', 200)));

        $bookings = $query->orderByDesc('starts_at')->limit($limit)->get();

        return response()->json($bookings->map(Present::booking(...))->all());
    }

    public function store(BookingRequest $request): JsonResponse
    {
        $station = $this->context->find(Station::class, (int) $request->input('station_id'));

        $starts = $request->date('starts_at');
        $ends = $request->date('ends_at');

        if ($clash = $this->overlapping($station->id, $starts, $ends)) {
            return response()->json(['message' => $this->clashMessage($clash)], 409);
        }

        $booking = Booking::create([
            'cafe_id' => $this->context->id(),
            'station_id' => $station->id,
            'customer_name' => $request->string('customer_name')->value(),
            'customer_phone' => $request->string('customer_phone')->value(),
            'starts_at' => $starts,
            'ends_at' => $ends,
            'controllers' => (int) $request->input('controllers', 1),
            'status' => 'booked',
            'note' => (string) $request->string('note', ''),
            'created_by_email' => $this->context->actorEmail(),
            'created_at' => now(),
        ]);

        $this->audit->log('booking_create', 'booking', $booking->id, sprintf(
            'Booked %s for %s at %s',
            $station->name,
            $booking->customer_name,
            $booking->starts_at->format('d M H:i'),
        ));

        return response()->json(Present::booking($booking->load('station')), Response::HTTP_CREATED);
    }

    public function update(BookingRequest $request, int $id): JsonResponse
    {
        $booking = $this->context->find(Booking::class, $id);

        if ($booking->status !== 'booked') {
            return response()->json(['message' => 'Only a reservation still on the books can be moved.'], 400);
        }

        $stationId = $request->filled('station_id')
            ? $this->context->find(Station::class, (int) $request->input('station_id'))->id
            : $booking->station_id;

        $starts = $request->filled('starts_at') ? $request->date('starts_at') : $booking->starts_at;
        $ends = $request->filled('ends_at') ? $request->date('ends_at') : $booking->ends_at;

        if ($clash = $this->overlapping($stationId, $starts, $ends, $booking->id)) {
            return response()->json(['message' => $this->clashMessage($clash)], 409);
        }

        $booking->fill($request->safe()->only(['customer_name', 'customer_phone', 'controllers', 'note']));
        $booking->station_id = $stationId;
        $booking->starts_at = $starts;
        $booking->ends_at = $ends;
        $booking->save();

        $this->audit->log('booking_update', 'booking', $booking->id, sprintf(
            'Moved booking #%d to %s',
            $booking->id,
            $booking->starts_at->format('d M H:i'),
        ));

        return response()->json(Present::booking($booking->load('station')));
    }

    /** arrived → a live session, carrying the booked length across. */
    public function start(int $id): JsonResponse
    {
        $booking = $this->context->find(Booking::class, $id);

        if ($booking->status !== 'booked') {
            return response()->json(['message' => 'This reservation is no longer on the books.'], 400);
        }

        $station = $this->context->find(Station::class, $booking->station_id);

        $session = $this->sessions->checkIn($station, [
            'name' => $booking->customer_name,
            'phone_or_id' => $booking->customer_phone,
            'controllers' => $booking->controllers,
            // The booked length becomes planned_minutes, so the dashboard can
            // flag the session as overdue when it runs past it (§5.5).
            'planned_minutes' => max(5, min(1440, $booking->starts_at->diffInMinutes($booking->ends_at))),
        ]);

        $booking->status = 'arrived';
        $booking->session_id = $session->id;
        $booking->save();

        $this->audit->log('booking_start', 'booking', $booking->id, sprintf(
            '%s arrived for booking #%d on %s',
            $booking->customer_name,
            $booking->id,
            $station->name,
        ));

        return response()->json([
            'booking' => Present::booking($booking->load('station')),
            'session' => Present::session($session->load(['station', 'customer'])),
        ], Response::HTTP_CREATED);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $booking = $this->context->find(Booking::class, $id);

        if ($booking->status !== 'booked') {
            return response()->json(['message' => 'This reservation is no longer on the books.'], 400);
        }

        $booking->status = $request->boolean('no_show') ? 'no_show' : 'cancelled';
        $booking->save();

        $this->audit->log('booking_cancel', 'booking', $booking->id, sprintf(
            'Booking #%d marked %s',
            $booking->id,
            $booking->status === 'no_show' ? 'a no-show' : 'cancelled',
        ));

        return response()->json(Present::booking($booking->load('station')));
    }

    /**
     * Two reservations clash when one starts before the other ends AND ends
     * after the other starts. Touching slots (10:00–11:00 then 11:00–12:00) do
     * not overlap and are allowed.
     *
     * Only reservations still on the books block a slot — a cancelled or
     * no-show booking frees its time.
     */
    private function overlapping(int $stationId, $starts, $ends, ?int $ignoreId = null): ?Booking
    {
        return Booking::where('station_id', $stationId)
            ->where('status', 'booked')
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->where('starts_at', '<', $ends)
            ->where('ends_at', '>', $starts)
            ->orderBy('starts_at')
            ->first();
    }

    /** The error names who already has the slot and when (§5.5). */
    private function clashMessage(Booking $clash): string
    {
        return sprintf(
            '%s already has this station from %s to %s.',
            $clash->customer_name,
            $clash->starts_at->format('d M H:i'),
            $clash->ends_at->format('H:i'),
        );
    }
}
