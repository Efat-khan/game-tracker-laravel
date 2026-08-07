<?php

namespace App\Services;

/**
 * §7.2 — signed QR codes.
 *
 * Station ids are sequential, so a bare /checkin/{id} link is guessable and a
 * bored player could start sessions on booths they never walked up to. Every
 * generated QR carries a signature over the station id.
 */
class StationTokenService
{
    public function token(int $stationId): string
    {
        return substr(
            hash_hmac('sha256', "station:{$stationId}", (string) config('cafetrack.qr_secret')),
            0,
            16,
        );
    }

    public function checkinUrl(int $stationId): string
    {
        return sprintf(
            '%s/checkin/%d?t=%s',
            config('cafetrack.frontend_base_url'),
            $stationId,
            $this->token($stationId),
        );
    }

    /** Constant time, so the signature cannot be guessed a character at a time. */
    public function verify(int $stationId, ?string $candidate): bool
    {
        if ($candidate === null || $candidate === '') {
            return false;
        }

        return hash_equals($this->token($stationId), $candidate);
    }
}
