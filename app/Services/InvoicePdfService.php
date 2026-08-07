<?php

namespace App\Services;

use App\Models\Cafe;
use App\Models\Invoice;
use App\Support\Money;
use Brick\Math\RoundingMode;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\View;

/** §6.6 — the A5 receipt handed to the player. */
class InvoicePdfService
{
    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing(['items', 'session.station', 'session.customer']);

        $session = $invoice->session;
        $minutes = (int) $invoice->duration_minutes;

        $html = View::make('invoices.receipt', [
            'invoice' => $invoice,
            'cafe' => Cafe::find($invoice->cafe_id),
            'createdAt' => $invoice->created_at?->format('d M Y, H:i'),
            'stationName' => $session?->station?->name ?? '—',
            'customerName' => $session?->customer?->name ?? '—',
            'customerPhone' => $session?->customer?->phone_or_id ?? '—',
            'startTime' => $session?->start_time?->format('d M Y, H:i') ?? '—',
            'endTime' => $session?->end_time?->format('d M Y, H:i') ?? '—',
            // "90 min (1.50 h)"
            'billedTime' => sprintf(
                '%d min (%s h)',
                $minutes,
                Money::of($minutes)->dividedBy(60, 2, RoundingMode::HalfUp),
            ),
            'controllers' => $session?->controllers ?? 1,
            'hourlyRate' => $session ? Money::str($session->hourly_rate_snapshot) : '0.00',
            'sessionAmount' => Money::str($invoice->session_amount),
            'discountAmount' => Money::str($invoice->discount_amount),
            'hasDiscount' => Money::of($invoice->discount_amount)->isPositive(),
            'totalAmount' => Money::str($invoice->total_amount),
            'paymentMethod' => match ($invoice->payment_method) {
                'cash' => 'Cash',
                'phone_payment' => 'Phone payment',
                'wallet' => 'Wallet',
                default => 'Not taken yet',
            },
        ])->render();

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A5', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
