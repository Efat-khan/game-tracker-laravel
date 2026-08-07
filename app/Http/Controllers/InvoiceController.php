<?php

namespace App\Http\Controllers;

use App\Http\Requests\DiscountRequest;
use App\Http\Requests\InvoiceItemRequest;
use App\Http\Requests\InvoiceUpdateRequest;
use App\Http\Requests\ReasonRequest;
use App\Http\Resources\Present;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Services\InvoiceService;
use App\Support\Money;
use App\Support\Tenancy\CafeContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly CafeContext $context,
        private readonly InvoiceService $invoices,
    ) {}

    private function filtered(Request $request): Builder
    {
        $query = $this->context->scope(Invoice::class)
            ->with(['items', 'session.station', 'session.customer']);

        if (in_array($request->query('payment_status'), ['paid', 'unpaid'], true)) {
            $query->where('payment_status', $request->query('payment_status'));
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date('date_from')->startOfDay());
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date('date_to')->endOfDay());
        }

        return $query->orderByDesc('id');
    }

    public function index(Request $request): JsonResponse
    {
        $limit = min(1000, max(1, (int) $request->query('limit', 200)));

        $invoices = $this->filtered($request)->limit($limit)->get();

        return response()->json($invoices->map(fn (Invoice $i) => Present::invoice($i))->all());
    }

    /** §6.5 — exactly these columns, in this order. */
    public function exportCsv(Request $request): StreamedResponse
    {
        $limit = min(1000, max(1, (int) $request->query('limit', 200)));
        $invoices = $this->filtered($request)->limit($limit)->get();

        $filename = 'invoices-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($invoices) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'invoice_id', 'created_at', 'station', 'customer', 'duration_minutes',
                'controllers', 'hourly_rate', 'total_amount', 'payment_method', 'payment_status',
            ]);

            foreach ($invoices as $invoice) {
                $session = $invoice->session;

                fputcsv($out, [
                    $invoice->id,
                    Present::time($invoice->created_at),
                    $session?->station?->name,
                    $session?->customer?->name,
                    $invoice->duration_minutes,
                    $session?->controllers,
                    $session ? Money::str($session->hourly_rate_snapshot) : '',
                    Money::str($invoice->total_amount),
                    $invoice->payment_method,
                    $invoice->payment_status,
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function pdf(int $id): Response
    {
        $invoice = $this->context->find(Invoice::class, $id);

        $pdf = app(\App\Services\InvoicePdfService::class)->render($invoice);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="invoice-%d.pdf"', $invoice->id),
        ]);
    }

    /**
     * Staff may mark an UNPAID invoice PAID. They may not change the payment
     * method, and they may not revert a paid invoice to unpaid (§3).
     */
    public function update(InvoiceUpdateRequest $request, int $id): JsonResponse
    {
        $invoice = $this->context->find(Invoice::class, $id);
        $isAdmin = $this->context->isAdmin();

        if ($invoice->isVoid()) {
            return response()->json(['message' => 'A voided invoice cannot be changed.'], 400);
        }

        $status = $request->input('payment_status');
        $method = $request->input('payment_method');

        if (! $isAdmin) {
            if ($method !== null && $method !== $invoice->payment_method) {
                return response()->json([
                    'message' => 'Only an admin can change the payment method.',
                ], 403);
            }

            if ($status === 'unpaid' && $invoice->payment_status === 'paid') {
                return response()->json([
                    'message' => 'Only an admin can revert a paid invoice to unpaid.',
                ], 403);
            }
        }

        if ($status === 'paid' && $invoice->payment_status !== 'paid') {
            $this->invoices->markPaid($invoice, $method ?? $invoice->payment_method ?? 'cash');
        } elseif ($status === 'unpaid' && $invoice->payment_status === 'paid') {
            $this->invoices->markUnpaid($invoice);
        }

        if ($method !== null && $isAdmin && $method !== $invoice->payment_method) {
            $invoice->payment_method = $method;
            $invoice->save();
        }

        return response()->json(Present::invoice($invoice->fresh(['items', 'session.station', 'session.customer'])));
    }

    public function addItem(InvoiceItemRequest $request, int $id): JsonResponse
    {
        $invoice = $this->context->find(Invoice::class, $id);

        if ($invoice->isVoid()) {
            return response()->json(['message' => 'A voided invoice cannot be changed.'], 400);
        }

        $data = $request->validated();

        // Selling from the catalogue snapshots the product's price AND cost, so
        // profit reports stay right after supplier prices change (§5.2).
        if (! empty($data['product_id'])) {
            $product = $this->context->find(Product::class, $data['product_id']);

            $data['description'] ??= $product->name;
            $data['unit_price'] ??= $product->price;
            $data['unit_cost'] ??= $product->cost_price;
        }

        $data['quantity'] ??= 1;
        $data['unit_cost'] ??= 0;

        $item = $this->invoices->addItem($invoice, $data);

        return response()->json([
            'item' => Present::invoiceItem($item),
            'invoice' => Present::invoice($invoice->fresh(['items', 'session.station', 'session.customer'])),
        ], Response::HTTP_CREATED);
    }

    public function removeItem(int $id, int $itemId): Response
    {
        $invoice = $this->context->find(Invoice::class, $id);

        if ($invoice->isVoid()) {
            return response()->json(['message' => 'A voided invoice cannot be changed.'], 400);
        }

        // Scoped through its invoice — invoice_items carries no cafe_id.
        $item = InvoiceItem::where('invoice_id', $invoice->id)->whereKey($itemId)->first();

        if ($item === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $this->invoices->removeItem($invoice, $item);

        return response()->noContent();
    }

    /** Admin only. */
    public function discount(DiscountRequest $request, int $id): JsonResponse
    {
        $invoice = $this->context->find(Invoice::class, $id);

        if ($invoice->isVoid()) {
            return response()->json(['message' => 'A voided invoice cannot be changed.'], 400);
        }

        $this->invoices->applyDiscount(
            $invoice,
            $request->filled('amount') ? (string) $request->input('amount') : null,
            $request->filled('percent') ? (string) $request->input('percent') : null,
            $request->string('reason')->value(),
        );

        return response()->json(Present::invoice($invoice->fresh(['items', 'session.station', 'session.customer'])));
    }

    /** Admin only. The invoice is kept for the record and excluded from revenue. */
    public function void(ReasonRequest $request, int $id): JsonResponse
    {
        $invoice = $this->context->find(Invoice::class, $id);

        if ($invoice->isVoid()) {
            return response()->json(['message' => 'This invoice is already void.'], 400);
        }

        $this->invoices->void($invoice->load('session'), $request->string('reason')->value());

        return response()->json(Present::invoice($invoice->fresh(['items', 'session.station', 'session.customer'])));
    }

    public function payWallet(int $id): JsonResponse
    {
        $invoice = $this->context->find(Invoice::class, $id);

        if ($invoice->isVoid()) {
            return response()->json(['message' => 'A voided invoice cannot be paid.'], 400);
        }

        if ($invoice->payment_status === 'paid') {
            return response()->json(['message' => 'This invoice is already paid.'], 400);
        }

        $customer = Customer::find($invoice->session?->customer_id);

        if ($customer === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $total = Money::of($invoice->total_amount);

        if (! app(\App\Services\WalletService::class)->hasBalance($customer, $total)) {
            return response()->json([
                'message' => sprintf(
                    'Not enough balance: %s available, %s needed.',
                    Money::str($customer->balance),
                    Money::str($total),
                ),
            ], 400);
        }

        $this->invoices->payFromWallet($invoice, $customer);

        return response()->json(Present::invoice($invoice->fresh(['items', 'session.station', 'session.customer'])));
    }
}
