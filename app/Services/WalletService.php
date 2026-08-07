<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\WalletTransaction;
use App\Support\Money;
use App\Support\Tenancy\CafeContext;
use Brick\Math\BigDecimal;

/**
 * §5.3 — prepaid credit.
 *
 * EVERY change to customers.balance goes through here, because every change
 * must write a ledger row carrying the signed amount and the resulting
 * balance_after. The ledger is the audit trail; the balance is a cache of it.
 */
class WalletService
{
    public function __construct(private readonly CafeContext $context) {}

    /**
     * @param  'topup'|'spend'|'refund'|'adjust'  $kind
     */
    public function record(
        Customer $customer,
        string $kind,
        BigDecimal $amount,
        array $attributes = [],
    ): WalletTransaction {
        $balanceAfter = Money::round(Money::of($customer->balance)->plus($amount));

        $customer->balance = (string) $balanceAfter;
        $customer->save();

        return WalletTransaction::create(array_merge([
            'cafe_id' => $customer->cafe_id,
            'customer_id' => $customer->id,
            'kind' => $kind,
            'amount' => (string) Money::round($amount),
            'balance_after' => (string) $balanceAfter,
            'actor_email' => $this->context->actorEmail(),
            'created_at' => now(),
        ], $attributes));
    }

    public function hasBalance(Customer $customer, BigDecimal $amount): bool
    {
        return Money::of($customer->balance)->isGreaterThanOrEqualTo($amount);
    }
}
