<?php

namespace App\Http\Resources;

use App\Models\Booking;
use App\Models\Cafe;
use App\Models\Customer;
use App\Models\GameSession;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MembershipTier;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Station;
use App\Models\WalletTransaction;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * The wire format, in one place (§6.3).
 *
 * Two rules hold everywhere: money is a STRING ("250.00"), never a float, and
 * timestamps are naive UTC ISO-8601 with no offset suffix — the frontend and
 * the FastAPI reference both expect exactly that.
 */
final class Present
{
    public static function time(?CarbonInterface $at): ?string
    {
        return $at?->utc()->format('Y-m-d\TH:i:s');
    }

    public static function station(Station $station): array
    {
        return [
            'id' => $station->id,
            'name' => $station->name,
            'type' => $station->type,
            'hourly_rate' => Money::str($station->hourly_rate),
            'extra_controller_rate' => Money::str($station->extra_controller_rate),
            'max_controllers' => $station->max_controllers,
            'qr_code_url' => $station->qr_code_url,
            'is_active' => (bool) $station->is_active,
            'maintenance' => (bool) $station->maintenance,
            'created_at' => self::time($station->created_at),
        ];
    }

    public static function session(GameSession $session): array
    {
        return [
            'id' => $session->id,
            'station_id' => $session->station_id,
            'station_name' => $session->station?->name,
            'customer_id' => $session->customer_id,
            'customer_name' => $session->customer?->name,
            'start_time' => self::time($session->start_time),
            'end_time' => self::time($session->end_time),
            'status' => $session->status,
            'hourly_rate' => Money::str($session->hourly_rate_snapshot),
            'base_rate' => Money::str($session->base_rate_snapshot),
            'extra_controller_rate' => Money::str($session->extra_controller_rate_snapshot),
            'controllers' => $session->controllers,
            'planned_minutes' => $session->planned_minutes,
            'invoice_id' => $session->invoice?->id,
        ];
    }

    public static function invoice(Invoice $invoice, bool $withItems = true): array
    {
        $session = $invoice->session;

        $payload = [
            'id' => $invoice->id,
            'session_id' => $invoice->session_id,
            'station_name' => $session?->station?->name,
            'customer_name' => $session?->customer?->name,
            'customer_id' => $session?->customer_id,
            'total_amount' => Money::str($invoice->total_amount),
            'session_amount' => Money::str($invoice->session_amount),
            'items_amount' => Money::str($invoice->items_amount),
            'discount_amount' => Money::str($invoice->discount_amount),
            'discount_reason' => $invoice->discount_reason,
            'duration_minutes' => $invoice->duration_minutes,
            'controllers' => $session?->controllers,
            'hourly_rate' => $session ? Money::str($session->hourly_rate_snapshot) : null,
            'payment_method' => $invoice->payment_method,
            'payment_status' => $invoice->payment_status,
            'status' => $invoice->status,
            'void_reason' => $invoice->void_reason,
            'paid_at' => self::time($invoice->paid_at),
            'shift_id' => $invoice->shift_id,
            'created_at' => self::time($invoice->created_at),
        ];

        if ($withItems) {
            $payload['items'] = $invoice->items->map(self::invoiceItem(...))->all();
        }

        return $payload;
    }

    public static function invoiceItem(InvoiceItem $item): array
    {
        return [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit_price' => Money::str($item->unit_price),
            'amount' => Money::str($item->amount),
        ];
    }

    public static function product(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'category' => $product->category,
            'price' => Money::str($product->price),
            'cost_price' => Money::str($product->cost_price),
            'is_active' => (bool) $product->is_active,
        ];
    }

    public static function package(Package $package): array
    {
        return [
            'id' => $package->id,
            'name' => $package->name,
            'price' => Money::str($package->price),
            'credit' => Money::str($package->credit),
            'is_active' => (bool) $package->is_active,
        ];
    }

    public static function tier(MembershipTier $tier): array
    {
        return [
            'id' => $tier->id,
            'name' => $tier->name,
            'min_spend' => Money::str($tier->min_spend),
            'discount_percent' => Money::str($tier->discount_percent),
            'is_active' => (bool) $tier->is_active,
        ];
    }

    public static function customer(
        Customer $customer,
        ?string $lifetimeSpend = null,
        ?int $visits = null,
        ?MembershipTier $tier = null,
    ): array {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone_or_id' => $customer->phone_or_id,
            'balance' => Money::str($customer->balance),
            'lifetime_spend' => $lifetimeSpend,
            'visits' => $visits,
            'tier_name' => $tier?->name,
            'tier_discount_percent' => $tier ? Money::str($tier->discount_percent) : null,
            'created_at' => self::time($customer->created_at),
        ];
    }

    public static function walletTransaction(WalletTransaction $tx): array
    {
        return [
            'id' => $tx->id,
            'kind' => $tx->kind,
            'amount' => Money::str($tx->amount),
            'balance_after' => Money::str($tx->balance_after),
            'invoice_id' => $tx->invoice_id,
            'package_id' => $tx->package_id,
            'note' => $tx->note,
            'actor_email' => $tx->actor_email,
            'payment_method' => $tx->payment_method,
            'created_at' => self::time($tx->created_at),
        ];
    }

    public static function booking(Booking $booking): array
    {
        return [
            'id' => $booking->id,
            'station_id' => $booking->station_id,
            'station_name' => $booking->station?->name,
            'customer_name' => $booking->customer_name,
            'customer_phone' => $booking->customer_phone,
            'starts_at' => self::time($booking->starts_at),
            'ends_at' => self::time($booking->ends_at),
            'controllers' => $booking->controllers,
            'status' => $booking->status,
            'note' => $booking->note,
            'session_id' => $booking->session_id,
            'created_by_email' => $booking->created_by_email,
            'created_at' => self::time($booking->created_at),
        ];
    }

    /** @param  array<string,string>  $totals */
    public static function shift(Shift $shift, array $totals = [], ?string $expectedCash = null): array
    {
        return array_merge([
            'id' => $shift->id,
            'opened_by_email' => $shift->opened_by_email,
            'opened_at' => self::time($shift->opened_at),
            'opening_float' => Money::str($shift->opening_float),
            'open_note' => $shift->open_note,
            'closed_by_email' => $shift->closed_by_email,
            'closed_at' => self::time($shift->closed_at),
            'counted_cash' => $shift->counted_cash === null ? null : Money::str($shift->counted_cash),
            'expected_cash' => $expectedCash ?? ($shift->expected_cash === null ? null : Money::str($shift->expected_cash)),
            'variance' => $shift->variance === null ? null : Money::str($shift->variance),
            'close_note' => $shift->close_note,
            'status' => $shift->status,
        ], $totals === [] ? [] : ['totals' => $totals]);
    }

    public static function cafe(Cafe $cafe, ?int $stationCount = null, ?int $accountCount = null): array
    {
        return [
            'id' => $cafe->id,
            'name' => $cafe->name,
            'slug' => $cafe->slug,
            'contact_email' => $cafe->contact_email,
            'is_active' => (bool) $cafe->is_active,
            'station_count' => $stationCount,
            'account_count' => $accountCount,
            'created_at' => self::time($cafe->created_at),
        ];
    }
}
