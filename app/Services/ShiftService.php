<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\Invoice;
use App\Models\Shift;
use App\Models\WalletTransaction;
use App\Support\Money;
use Brick\Math\BigDecimal;

/**
 * §5.4 — shifts and the cash drawer.
 *
 * The rule that matters: only cash touches the drawer. Phone payments and
 * wallet spends are deliberately excluded from expected_cash — that money never
 * entered the till (wallet credit was paid for back at top-up time, and counted
 * in the drawer then).
 */
class ShiftService
{
    /** One open shift per cafe at a time. */
    public function current(int $cafeId): ?Shift
    {
        return Shift::where('cafe_id', $cafeId)->where('status', 'open')->latest('opened_at')->first();
    }

    /**
     * Live totals, split by the method the money arrived in.
     *
     * @return array<string,string>
     */
    public function totals(Shift $shift): array
    {
        $sales = Invoice::query()
            ->where('shift_id', $shift->id)
            ->where('status', '!=', 'void')
            ->where('payment_status', 'paid');

        $cashSales = $this->sum((clone $sales)->where('payment_method', 'cash')->sum('total_amount'));
        $phoneSales = $this->sum((clone $sales)->where('payment_method', 'phone_payment')->sum('total_amount'));
        $walletSales = $this->sum((clone $sales)->where('payment_method', 'wallet')->sum('total_amount'));

        $topups = WalletTransaction::where('shift_id', $shift->id)->where('kind', 'topup');
        $cashTopups = $this->sum((clone $topups)->where('payment_method', 'cash')->sum('amount'));
        $phoneTopups = $this->sum((clone $topups)->where('payment_method', 'phone_payment')->sum('amount'));

        $paidIn = $this->sum(CashMovement::where('shift_id', $shift->id)->where('kind', 'in')->sum('amount'));
        $paidOut = $this->sum(CashMovement::where('shift_id', $shift->id)->where('kind', 'out')->sum('amount'));

        return [
            'cash_sales' => Money::str($cashSales),
            'phone_sales' => Money::str($phoneSales),
            'wallet_sales' => Money::str($walletSales),
            'cash_topups' => Money::str($cashTopups),
            'phone_topups' => Money::str($phoneTopups),
            'paid_in' => Money::str($paidIn),
            'paid_out' => Money::str($paidOut),
            'total_sales' => Money::str($cashSales->plus($phoneSales)->plus($walletSales)),
        ];
    }

    /**
     * expected_cash = opening_float + cash_sales + cash_topups + paid_in − paid_out
     *
     * A closed shift returns its FROZEN figures: a void the next day must not
     * rewrite a signed-off reconciliation.
     */
    public function expectedCash(Shift $shift): BigDecimal
    {
        if ($shift->isClosed() && $shift->expected_cash !== null) {
            return Money::of($shift->expected_cash);
        }

        $t = $this->totals($shift);

        return Money::round(
            Money::of($shift->opening_float)
                ->plus(Money::of($t['cash_sales']))
                ->plus(Money::of($t['cash_topups']))
                ->plus(Money::of($t['paid_in']))
                ->minus(Money::of($t['paid_out']))
        );
    }

    private function sum(mixed $value): BigDecimal
    {
        return Money::of(is_string($value) ? $value : (string) ($value ?? '0'));
    }
}
