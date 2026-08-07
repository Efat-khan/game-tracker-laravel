<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\Money;
use App\Support\Tenancy\CafeContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class InvoiceService
{
    public function __construct(
        private readonly CafeContext $context,
        private readonly ShiftService $shifts,
        private readonly WalletService $wallet,
        private readonly AuditService $audit,
    ) {}

    /**
     * The invariant every invoice must satisfy at all times:
     *
     *   total_amount = session_amount + items_amount − discount_amount
     *
     * Called after anything that moves one of the three parts.
     */
    public function recalculate(Invoice $invoice): Invoice
    {
        $items = Money::round(
            $invoice->items()->get()->reduce(
                fn (BigDecimal $carry, InvoiceItem $item) => $carry->plus(Money::of($item->amount)),
                Money::zero(),
            )
        );

        $total = Money::of($invoice->session_amount)
            ->plus($items)
            ->minus(Money::of($invoice->discount_amount));

        $invoice->items_amount = (string) $items;
        $invoice->total_amount = (string) Money::round($total);
        $invoice->save();

        return $invoice;
    }

    public function addItem(Invoice $invoice, array $data): InvoiceItem
    {
        $quantity = (int) $data['quantity'];
        $unitPrice = Money::of($data['unit_price']);
        // Both price AND cost are snapshotted so profit reports stay correct
        // after supplier prices change (§5.2).
        $unitCost = Money::of($data['unit_cost'] ?? 0);

        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $data['product_id'] ?? null,
            'description' => $data['description'],
            'quantity' => $quantity,
            'unit_price' => (string) Money::round($unitPrice),
            'unit_cost' => (string) Money::round($unitCost),
            'amount' => (string) Money::round($unitPrice->multipliedBy($quantity)),
            'created_at' => now(),
        ]);

        $this->recalculate($invoice);

        $this->audit->log(
            'invoice_item_add',
            'invoice',
            $invoice->id,
            sprintf('Added %d x %s to invoice #%d', $quantity, $data['description'], $invoice->id),
        );

        return $item;
    }

    public function removeItem(Invoice $invoice, InvoiceItem $item): void
    {
        $description = $item->description;
        $item->delete();

        $this->recalculate($invoice);

        $this->audit->log(
            'invoice_item_remove',
            'invoice',
            $invoice->id,
            sprintf('Removed %s from invoice #%d', $description, $invoice->id),
        );
    }

    /** Admin only. Takes a flat amount or a percent of the pre-discount subtotal. */
    public function applyDiscount(Invoice $invoice, ?string $amount, ?string $percent, string $reason): Invoice
    {
        $subtotal = Money::of($invoice->session_amount)->plus(Money::of($invoice->items_amount));

        $discount = $amount !== null
            ? Money::round($amount)
            : Money::round($subtotal->multipliedBy(Money::of($percent))->dividedBy(100, 8, RoundingMode::HalfUp));

        // A discount never exceeds what is owed, which would turn an invoice
        // into a payout.
        if ($discount->isGreaterThan($subtotal)) {
            $discount = $subtotal;
        }

        $invoice->discount_amount = (string) $discount;
        $invoice->discount_reason = mb_substr($reason, 0, 200);
        $invoice->save();

        $this->recalculate($invoice);

        $this->audit->log(
            'invoice_discount',
            'invoice',
            $invoice->id,
            sprintf('Discounted invoice #%d by %s — %s', $invoice->id, Money::str($discount), $reason),
        );

        return $invoice;
    }

    /**
     * Mark paid, recording WHEN and — importantly — which shift collected the
     * money. Takings land in the shift that took them, not the one that started
     * the session (§5.4).
     */
    public function markPaid(Invoice $invoice, string $method): Invoice
    {
        $invoice->payment_status = 'paid';
        $invoice->payment_method = $method;
        $invoice->paid_at = now();
        $invoice->shift_id = $this->shifts->current($invoice->cafe_id)?->id;
        $invoice->save();

        $this->audit->log(
            'invoice_paid',
            'invoice',
            $invoice->id,
            sprintf('Invoice #%d marked paid (%s, %s)', $invoice->id, $method, Money::str($invoice->total_amount)),
        );

        return $invoice;
    }

    public function logPaymentMethodChange(Invoice $invoice): void
    {
        $this->audit->log(
            'invoice_payment_method',
            'invoice',
            $invoice->id,
            sprintf('Invoice #%d payment method set to %s', $invoice->id, $invoice->payment_method),
        );
    }

    /** Admin only — staff cannot revert a paid invoice (§3). */
    public function markUnpaid(Invoice $invoice): Invoice
    {
        $invoice->payment_status = 'unpaid';
        $invoice->paid_at = null;
        $invoice->shift_id = null;
        $invoice->save();

        $this->audit->log(
            'invoice_unpaid',
            'invoice',
            $invoice->id,
            sprintf('Invoice #%d reverted to unpaid', $invoice->id),
        );

        return $invoice;
    }

    /** Settle from prepaid credit. The caller has already checked the balance. */
    public function payFromWallet(Invoice $invoice, Customer $customer): Invoice
    {
        $total = Money::of($invoice->total_amount);

        $this->wallet->record($customer, 'spend', $total->negated(), [
            'invoice_id' => $invoice->id,
            'note' => sprintf('Invoice #%d', $invoice->id),
            'shift_id' => $this->shifts->current($invoice->cafe_id)?->id,
        ]);

        // payment_method='wallet' is set here by the system; it is never
        // accepted from a client (§6.2).
        return $this->markPaid($invoice, 'wallet');
    }

    /**
     * Admin only. The invoice is KEPT for the record and excluded from all
     * revenue, analytics and lifetime spend. A wallet-paid invoice gives the
     * credit back and writes a refund row (§5.3).
     */
    public function void(Invoice $invoice, string $reason): Invoice
    {
        if ($invoice->payment_method === 'wallet' && $invoice->payment_status === 'paid') {
            $customer = Customer::find($invoice->session?->customer_id);

            if ($customer !== null) {
                $this->wallet->record($customer, 'refund', Money::of($invoice->total_amount), [
                    'invoice_id' => $invoice->id,
                    'note' => sprintf('Refund for voided invoice #%d', $invoice->id),
                ]);
            }
        }

        $invoice->status = 'void';
        $invoice->void_reason = mb_substr($reason, 0, 200);
        $invoice->save();

        $this->audit->log(
            'invoice_void',
            'invoice',
            $invoice->id,
            sprintf('Voided invoice #%d — %s', $invoice->id, $reason),
        );

        return $invoice;
    }
}
