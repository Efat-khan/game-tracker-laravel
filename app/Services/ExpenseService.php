<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\Expense;
use App\Support\Money;
use App\Support\Tenancy\CafeContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The expense ledger, and the one rule that ties it to the cash drawer.
 *
 * Paying in cash takes money out of the till, so it has to reach the shift
 * reconciliation or the drawer will not balance at closing. Paying by bank or
 * phone does not touch the till at all. The whole of that difference lives
 * here, so neither the controller nor the reports have to think about it.
 */
class ExpenseService
{
    public function __construct(
        private readonly CafeContext $context,
        private readonly ShiftService $shifts,
        private readonly AuditService $audit,
    ) {}

    /**
     * Record an expense, writing the matching cash movement when it was paid
     * out of the drawer.
     *
     * @throws ConflictHttpException when cash is spent with no shift open
     */
    public function record(array $data): Expense
    {
        $cafeId = $this->context->id();
        $isCash = $data['payment_method'] === 'cash';
        $shift = $isCash ? $this->shifts->current($cafeId) : null;

        if ($isCash && $shift === null) {
            throw new ConflictHttpException(
                'Cash comes out of the open drawer, so a shift has to be open. '
                .'Open one on the Shifts screen, or record this as a bank or phone payment.'
            );
        }

        // Cash is pinned to today: it left the drawer that is open right now,
        // and yesterday's shift has already been counted and signed off.
        $spentOn = $isCash
            ? now()->toDateString()
            : ($data['spent_on'] ?? now()->toDateString());

        $amount = Money::str($data['amount']);
        $note = trim((string) ($data['note'] ?? ''));

        return DB::transaction(function () use ($cafeId, $shift, $data, $amount, $note, $spentOn, $isCash) {
            $movement = null;

            if ($isCash) {
                $movement = CashMovement::create([
                    'shift_id' => $shift->id,
                    'kind' => 'out',
                    'amount' => $amount,
                    // The drawer's own record of why the money left, so the
                    // Shifts screen reads on its own without joining anything.
                    'reason' => $this->reasonFor($data['category'], $note),
                    'actor_email' => $this->context->actorEmail(),
                    'created_at' => now(),
                ]);
            }

            $expense = Expense::create([
                'cafe_id' => $cafeId,
                'shift_id' => $shift?->id,
                'cash_movement_id' => $movement?->id,
                'category' => $data['category'],
                'amount' => $amount,
                'payment_method' => $data['payment_method'],
                'note' => $note,
                'spent_on' => $spentOn,
                'actor_email' => $this->context->actorEmail(),
                'created_at' => now(),
            ]);

            $this->audit->log('expense_create', 'expense', $expense->id, sprintf(
                '%s expense of %s by %s%s',
                $expense->categoryLabel(),
                Money::str($expense->amount),
                str_replace('_', ' ', $expense->payment_method),
                $note === '' ? '' : " — {$note}",
            ));

            return $expense;
        });
    }

    /**
     * Delete an expense, taking its cash movement with it.
     *
     * Refused once the shift it came out of has been closed. A counted,
     * signed-off drawer must not move afterwards — the same rule that freezes
     * a closed shift's expected_cash.
     *
     * @throws ConflictHttpException
     */
    public function remove(Expense $expense): void
    {
        if ($expense->shift_id !== null && $expense->shift?->isClosed()) {
            throw new ConflictHttpException(
                'That shift has been closed and counted, so this can no longer be removed. '
                .'Record a correcting entry instead.'
            );
        }

        DB::transaction(function () use ($expense) {
            $movementId = $expense->cash_movement_id;
            $summary = sprintf(
                '%s expense of %s',
                $expense->categoryLabel(),
                Money::str($expense->amount),
            );
            $id = $expense->id;

            $expense->delete();

            if ($movementId !== null) {
                CashMovement::whereKey($movementId)->delete();
            }

            $this->audit->log('expense_delete', 'expense', $id, "Deleted {$summary}");
        });
    }

    private function reasonFor(string $category, string $note): string
    {
        $label = Expense::CATEGORIES[$category] ?? $category;

        return mb_substr($note === '' ? $label : "{$label} — {$note}", 0, 200);
    }
}
