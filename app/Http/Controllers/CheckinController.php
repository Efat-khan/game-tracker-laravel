<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckinRequest;
use App\Http\Resources\Present;
use App\Models\Station;
use App\Services\SessionService;
use App\Services\StationTokenService;
use App\Support\Tenancy\CafeContext;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public check-in. A player scans the booth's QR sticker, the page opens on
 * their own phone, and they start their own session. They have no account and
 * cannot stop their own timer — only staff can.
 */
class CheckinController extends Controller
{
    public function __construct(
        private readonly CafeContext $context,
        private readonly SessionService $sessions,
        private readonly StationTokenService $qr,
    ) {}

    public function store(CheckinRequest $request, int $stationId): JsonResponse
    {
        $station = Station::find($stationId);

        if ($station === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        // Staff starting a session from the dashboard have no code to scan, so
        // they bypass the signature check (§7.2).
        $isStaff = $request->user() !== null;

        if (! $isStaff && config('cafetrack.require_qr_token')) {
            if (! $this->qr->verify($station->id, $request->query('t'))) {
                return response()->json(['message' => 'Scan the QR code on the station to check in.'], 403);
            }
        }

        // The cafe comes from the station, never from a header: an anonymous
        // player has no token to carry one.
        $this->context->bind($request->user(), $station->cafe_id);

        $session = $this->sessions->checkIn($station, $request->validated());

        return response()->json(
            Present::session($session->load(['station', 'customer'])),
            Response::HTTP_CREATED,
        );
    }
}
