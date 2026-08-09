<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\GameSession;
use App\Models\Invoice;
use App\Models\Station;
use App\Support\Money;
use App\Support\Tenancy\CafeContext;
use Carbon\CarbonInterface;
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

    /**
     * Run the whole §5.1 pipeline for a session ending at a given moment.
     *
     * Both the quote and the checkout go through here, so the figure the
     * operator is shown before confirming is the figure that gets charged —
     * agreeing by construction rather than by two code paths happening to do
     * the same arithmetic.
     */
    private function price(GameSession $session, CarbonInterface $end): array
    {
        $cafeId = $session->cafe_id;

        $block = $this->settings->billingRoundMinutes($cafeId);
        $step = $this->settings->roundAmountTo($cafeId);

        $priced = $this->billing->priceSession($session, $end, $block, $step);

        // Step 3: the loyalty discount comes off the rounded play charge.
        $discount = $this->loyalty->discountFor($session->customer, $priced['total']);

        return [
            'priced' => $priced,
            'discount' => $discount,
            'block' => $block,
            'step' => $step,
            'total' => Money::round($priced['total']->minus($discount['amount'])),
        ];
    }

    /**
     * What this session bills if it ends right now, itemised.
     *
     * The running cost on the floor is deliberately unrounded — it is a ticking
     * display, not a bill. This is the bill: time rounded up to a whole block,
     * the amount rounded to the cafe's step, and any tier discount taken off.
     * Nothing is written.
     */
    public function quote(GameSession $session): array
    {
        $q = $this->price($session, now());
        $priced = $q['priced'];

        return [
            'session_id' => $session->id,
            'station_name' => $session->station->name,
            'customer_name' => $session->customer?->name,
            'controllers' => $session->controllers,
            'hourly_rate' => Money::str($priced['effective_rate']),
            'actual_minutes' => $priced['actual_minutes'],
            'billed_minutes' => $priced['billed_minutes'],
            // Echoed so the screen can say WHY the numbers moved, rather than
            // just asserting a total the operator has to take on trust.
            'block_minutes' => $q['block'],
            'round_amount_to' => $q['step'],
            'gross' => Money::str($priced['gross']),
            'rounding' => Money::str($priced['total']->minus($priced['gross'])),
            'subtotal' => Money::str($priced['total']),
            'discount' => Money::str($q['discount']['amount']),
            'discount_reason' => $q['discount']['reason'],
            'total' => Money::str($q['total']),
        ];
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

            $q = $this->price($session, $end);
            $priced = $q['priced'];
            $discount = $q['discount'];

            $session->end_time = $end;
            $session->status = 'completed';
            $session->save();

            $invoice = Invoice::create([
                'cafe_id' => $cafeId,
                'session_id' => $session->id,
                'session_amount' => (string) $priced['total'],
                'items_amount' => '0.00',
                'discount_amount' => (string) $discount['amount'],
                'discount_reason' => $discount['reason'],
                'total_amount' => (string) $q['total'],
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
