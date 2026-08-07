<?php

namespace App\Services;

use App\Models\GameSession;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;

/**
 * §5.1 — implemented exactly, in the order the spec gives.
 *
 *   Step 0  effective hourly rate, fixed at check-in
 *   Step 1  time rounds UP to a whole block
 *   Step 2  money rounds to the nearest step, and a nonzero bill never reaches 0
 *   Step 3  the loyalty discount comes off that total (applied by the caller)
 *
 * The live "cost so far" deliberately skips both rounding steps: it is a
 * running display, not a bill.
 */
class BillingService
{
    /**
     * Step 0. The station's hourly_rate covers the FIRST controller; each one
     * after that adds extra_controller_rate per hour.
     *
     *   ৳150 base + ৳50/extra with 3 controllers = ৳250/hr
     */
    public function effectiveRate(
        BigDecimal|string|int|float|null $baseRate,
        BigDecimal|string|int|float|null $extraRate,
        int $controllers,
    ): BigDecimal {
        $extras = max(0, $controllers - 1);

        return Money::round(
            Money::of($baseRate)->plus(Money::of($extraRate)->multipliedBy($extras))
        );
    }

    /**
     * Step 1. Time rounds UP to a whole block, and a session always bills for at
     * least one block — a 3-minute session on a 15-minute block bills as 15.
     * A block of 1 means per-minute billing.
     */
    public function billedMinutes(int $actualMinutes, int $blockMinutes): int
    {
        $block = max(1, $blockMinutes);
        $blocks = (int) ceil(max(0, $actualMinutes) / $block);

        return max(1, $blocks) * $block;
    }

    /** Whole elapsed minutes, floored — seconds never buy a free minute. */
    public function actualMinutes(CarbonInterface $start, CarbonInterface $end): int
    {
        return max(0, intdiv(max(0, $end->getTimestamp() - $start->getTimestamp()), 60));
    }

    /** The charge before the amount-rounding step: billed time × effective rate. */
    public function grossAmount(int $billedMinutes, BigDecimal|string|int|float|null $effectiveRate): BigDecimal
    {
        return Money::round(
            Money::of($effectiveRate)
                ->multipliedBy($billedMinutes)
                ->dividedBy(60, 8, RoundingMode::HalfUp)
        );
    }

    /**
     * Step 2. Round to the nearest step: ৳202 → ৳200, ৳400.56 → ৳400,
     * ৳102.50 → ৳105. A bill that is nonzero must never round away to nothing,
     * so it floors at one step.
     */
    public function roundToStep(BigDecimal $gross, int $step): BigDecimal
    {
        $step = max(1, $step);

        $total = Money::round(
            Money::of($gross)
                ->dividedBy($step, 0, RoundingMode::HalfUp)
                ->multipliedBy($step)
        );

        if ($gross->isPositive() && ! $total->isPositive()) {
            return Money::round($step);
        }

        return $total;
    }

    /**
     * The full end-of-session calculation for one session.
     *
     * @return array{actual_minutes:int, billed_minutes:int, effective_rate:BigDecimal, gross:BigDecimal, total:BigDecimal}
     */
    public function priceSession(GameSession $session, CarbonInterface $end, int $blockMinutes, int $step): array
    {
        $actual = $this->actualMinutes($session->start_time, $end);
        $billed = $this->billedMinutes($actual, $blockMinutes);
        // The snapshot, not the station's current rate: a price change must never
        // alter a session already running (§5.1).
        $rate = Money::of($session->hourly_rate_snapshot);
        $gross = $this->grossAmount($billed, $rate);

        return [
            'actual_minutes' => $actual,
            'billed_minutes' => $billed,
            'effective_rate' => $rate,
            'gross' => $gross,
            'total' => $this->roundToStep($gross, $step),
        ];
    }

    /**
     * The live "cost so far" — exact minutes, no block rounding and no amount
     * rounding. Both rounding steps apply only when the session ends.
     */
    public function runningCost(GameSession $session, CarbonInterface $now): BigDecimal
    {
        $minutes = $this->actualMinutes($session->start_time, $now);

        return Money::round(
            Money::of($session->hourly_rate_snapshot)
                ->multipliedBy($minutes)
                ->dividedBy(60, 8, RoundingMode::HalfUp)
        );
    }
}
