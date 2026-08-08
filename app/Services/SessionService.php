<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\GameSession;
use App\Models\Invoice;
use App\Models\Station;
use App\Support\Money;
use App\Support\Tenancy\CafeContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SessionService
{
    public function __construct(
        private readonly CafeContext $context,
        private readonly BillingService $billing,
        private readonly SettingsService $settings,
        private readonly LoyaltyService $loyalty,
        private readonly AuditService $audit,
    ) {}

    /**
     * Start a session. Reached either from the public QR page or from the staff
     * dashboard; the rules are identical.
     */
    public function checkIn(Station $station, array $data): GameSession
    {
        if (! $station->is_active) {
            throw new HttpException(400, 'This station is not in service.');
        }

        if ($station->maintenance) {
            throw new HttpException(400, 'This station is under maintenance.');
        }

        $controllers = (int) ($data['controllers'] ?? 1);

        if ($controllers > $station->max_controllers) {
            throw new HttpException(400, sprintf(
                'This station takes at most %d controllers.',
                $station->max_controllers,
            ));
        }

        return DB::transaction(function () use ($station, $data, $controllers) {
            // Lock the station so two phones scanning the same sticker at once
            // cannot both win the race.
            $locked = Station::whereKey($station->id)->lockForUpdate()->first();

            $active = GameSession::where('station_id', $locked->id)->where('status', 'active')->exists();

            if ($active) {
                throw new ConflictHttpException('This station already has a session running.');
            }

            $customer = $this->resolveCustomer($locked->cafe_id, $data['name'], $data['phone_or_id']);

            $effectiveRate = $this->billing->effectiveRate(
                $locked->rateMap(),
                $controllers,
                $locked->hourly_rate,
            );

            $session = GameSession::create([
                'cafe_id' => $locked->cafe_id,
                'station_id' => $locked->id,
                'customer_id' => $customer->id,
                'start_time' => now(),
                'status' => 'active',
                // Snapshotted so a later price change never alters this session.
                'hourly_rate_snapshot' => (string) $effectiveRate,
                'base_rate_snapshot' => (string) Money::round($locked->hourly_rate),
                // Kept for the record, and for rows written before rates were
                // looked up per controller count: what each extra pad cost on
                // average, which under a flat model was the whole story.
                'extra_controller_rate_snapshot' => (string) $this->billing->averageExtraRate(
                    $effectiveRate,
                    $locked->hourly_rate,
                    $controllers,
                ),
                'controllers' => $controllers,
                'planned_minutes' => $data['planned_minutes'] ?? null,
                'created_at' => now(),
            ]);

            $this->audit->log('session_start', 'session', $session->id, sprintf(
                '%s started on %s (%d controller%s, %s/hr)',
                $customer->name,
                $locked->name,
                $controllers,
                $controllers === 1 ? '' : 's',
                Money::str($effectiveRate),
            ));

            return $session;
        });
    }

    /**
     * Check-in matches an existing customer by (phone_or_id, cafe_id), else
     * creates one — and always updates the stored name to what was just typed.
     * The same phone in two cafes is two separate customers (§4.8).
     */
    private function resolveCustomer(int $cafeId, string $name, string $phoneOrId): Customer
    {
        $customer = Customer::where('cafe_id', $cafeId)->where('phone_or_id', $phoneOrId)->first();

        if ($customer === null) {
            return Customer::create([
                'cafe_id' => $cafeId,
                'name' => $name,
                'phone_or_id' => $phoneOrId,
                'balance' => '0.00',
                'created_at' => now(),
            ]);
        }

        $customer->name = $name;
        $customer->save();

        return $customer;
    }

    /** End a session and raise its invoice. Exactly one invoice per session. */
    public function checkOut(GameSession $session, ?string $paymentMethod = null): Invoice
    {
        if ($session->status !== 'active') {
            throw new ConflictHttpException('This session has already ended.');
        }

        return DB::transaction(function () use ($session, $paymentMethod) {
            $cafeId = $session->cafe_id;
            $end = now();

            $priced = $this->billing->priceSession(
                $session,
                $end,
                $this->settings->billingRoundMinutes($cafeId),
                $this->settings->roundAmountTo($cafeId),
            );

            $session->end_time = $end;
            $session->status = 'completed';
            $session->save();

            // Step 3: the loyalty discount comes off the rounded play charge.
            $discount = $this->loyalty->discountFor($session->customer, $priced['total']);

            $invoice = Invoice::create([
                'cafe_id' => $cafeId,
                'session_id' => $session->id,
                'session_amount' => (string) $priced['total'],
                'items_amount' => '0.00',
                'discount_amount' => (string) $discount['amount'],
                'discount_reason' => $discount['reason'],
                'total_amount' => (string) Money::round($priced['total']->minus($discount['amount'])),
                'duration_minutes' => $priced['billed_minutes'],
                'payment_status' => 'unpaid',
                'status' => 'active',
                'created_at' => now(),
            ]);

            $this->audit->log('session_end', 'session', $session->id, sprintf(
                '%s ended on %s — %d min, %s',
                $session->customer->name,
                $session->station->name,
                $priced['billed_minutes'],
                Money::str($invoice->total_amount),
            ));

            if ($paymentMethod !== null) {
                app(InvoiceService::class)->markPaid($invoice, $paymentMethod);
            }

            return $invoice->fresh();
        });
    }

    /** A mistaken start. Frees the device and raises no invoice at all (§5.2). */
    public function cancel(GameSession $session): GameSession
    {
        if ($session->status !== 'active') {
            throw new ConflictHttpException('This session has already ended.');
        }

        $session->status = 'cancelled';
        $session->end_time = now();
        $session->save();

        $this->audit->log('session_cancel', 'session', $session->id, sprintf(
            'Cancelled session #%d on %s (no charge)',
            $session->id,
            $session->station->name,
        ));

        return $session;
    }
}
