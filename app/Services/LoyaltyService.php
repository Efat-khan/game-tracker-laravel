<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MembershipTier;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * §5.3 — membership tiers, reached by lifetime spend.
 *
 * Lifetime spend is COMPUTED, never stored on the customer, so it cannot drift
 * when an invoice is voided. It counts paid, non-void invoices only.
 */
class LoyaltyService
{
    public function lifetimeSpend(Customer $customer): BigDecimal
    {
        $sum = Invoice::query()
            ->where('cafe_id', $customer->cafe_id)
            ->where('status', '!=', 'void')
            ->where('payment_status', 'paid')
            ->whereIn('session_id', function ($q) use ($customer) {
                $q->select('id')->from('sessions')->where('customer_id', $customer->id);
            })
            ->sum('total_amount');

        // Read the SQL SUM() as a string, never a PHP float.
        return Money::round(is_string($sum) ? $sum : (string) $sum);
    }

    /** The highest tier the customer has reached, or null below the first threshold. */
    public function tierFor(Customer $customer, ?BigDecimal $spend = null): ?MembershipTier
    {
        $spend ??= $this->lifetimeSpend($customer);

        return MembershipTier::query()
            ->where('cafe_id', $customer->cafe_id)
            ->where('is_active', true)
            ->whereRaw('CAST(min_spend AS DECIMAL(10,2)) <= ?', [(string) $spend])
            ->orderByDesc('min_spend')
            ->orderByDesc('discount_percent')
            ->first();
    }

    /**
     * The automatic checkout discount.
     *
     * A 0% tier (Bronze, typically) reaches nothing and leaves the invoice
     * clean rather than stamping a "Bronze member 0%" line on it.
     *
     * @return array{amount:BigDecimal, reason:?string, tier:?MembershipTier}
     */
    public function discountFor(Customer $customer, BigDecimal $base): array
    {
        $tier = $this->tierFor($customer);

        if ($tier === null) {
            return ['amount' => Money::zero(), 'reason' => null, 'tier' => null];
        }

        $percent = Money::of($tier->discount_percent);

        if (! $percent->isPositive() || ! $base->isPositive()) {
            return ['amount' => Money::zero(), 'reason' => null, 'tier' => $tier];
        }

        $amount = Money::round($base->multipliedBy($percent)->dividedBy(100, 8, RoundingMode::HalfUp));

        // Trailing zeros trimmed: "Silver member 5%", not "5.00%".
        $reason = sprintf('%s member %s%%', $tier->name, Money::trimPercent($percent));

        return ['amount' => $amount, 'reason' => $reason, 'tier' => $tier];
    }
}
