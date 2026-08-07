<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Cafe;
use App\Models\Product;
use App\Models\Station;
use App\Services\SettingsService;
use App\Support\Money;
use Tests\TestCase;

/** §5.2 — the point of sale: items, discounts, voids and who did what. */
class PosTest extends TestCase
{
    private Cafe $cafe;

    private AdminUser $admin;

    private AdminUser $staff;

    private Station $station;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cafe = $this->makeCafe();
        $this->admin = $this->makeUser($this->cafe, 'admin', 'admin@example.com');
        $this->staff = $this->makeUser($this->cafe, 'staff', 'staff@example.com');
        $this->station = $this->makeStation($this->cafe);

        app(SettingsService::class)->put($this->cafe->id, [
            'billing_round_minutes' => 1, 'round_amount_to' => 1,
        ]);

        $this->product = Product::create([
            'cafe_id' => $this->cafe->id, 'name' => 'Nachos with Cheese', 'category' => 'Snacks',
            'price' => '150.00', 'cost_price' => '88.00', 'created_at' => now(),
        ]);
    }

    /** 60 min at 150/hr = 150.00. */
    private function invoice(): array
    {
        $customer = $this->makeCustomer($this->cafe, ['phone_or_id' => '017'.mt_rand(10000000, 99999999)]);
        $session = $this->makeSession($this->cafe, $this->station, $customer, 60);

        return $this->apiPost($this->admin, "/api/checkout/{$session->id}")->assertCreated()->json();
    }

    private function assertReconciles(array $invoice): void
    {
        $expected = Money::of($invoice['session_amount'])
            ->plus(Money::of($invoice['items_amount']))
            ->minus(Money::of($invoice['discount_amount']));

        $this->assertSame(Money::str($expected), $invoice['total_amount']);
    }

    public function test_adding_an_item_raises_the_total_and_reconciles(): void
    {
        $invoice = $this->invoice();

        $result = $this->apiPost($this->staff, "/api/invoices/{$invoice['id']}/items", [
            'product_id' => $this->product->id,
            'quantity' => 2,
        ])->assertCreated()->json();

        $this->assertSame('300.00', $result['invoice']['items_amount']);
        $this->assertSame('450.00', $result['invoice']['total_amount']);
        $this->assertReconciles($result['invoice']);
    }

    public function test_a_free_typed_item_is_accepted_without_a_product(): void
    {
        $invoice = $this->invoice();

        $result = $this->apiPost($this->staff, "/api/invoices/{$invoice['id']}/items", [
            'description' => 'Borrowed headset',
            'unit_price' => '75.00',
            'quantity' => 1,
        ])->assertCreated()->json();

        $this->assertSame('225.00', $result['invoice']['total_amount']);
    }

    public function test_removing_an_item_restores_the_total(): void
    {
        $invoice = $this->invoice();

        $added = $this->apiPost($this->staff, "/api/invoices/{$invoice['id']}/items", [
            'product_id' => $this->product->id, 'quantity' => 1,
        ])->assertCreated()->json();

        $this->assertSame('300.00', $added['invoice']['total_amount']);

        $this->apiDelete($this->staff, "/api/invoices/{$invoice['id']}/items/{$added['item']['id']}")
            ->assertNoContent();

        $after = $this->apiGet($this->staff, "/api/invoices?limit=1")->json()[0];

        $this->assertSame('0.00', $after['items_amount']);
        $this->assertSame('150.00', $after['total_amount']);
        $this->assertReconciles($after);
    }

    public function test_an_item_snapshots_the_cost_price_at_sale_time(): void
    {
        $invoice = $this->invoice();

        $this->apiPost($this->staff, "/api/invoices/{$invoice['id']}/items", [
            'product_id' => $this->product->id, 'quantity' => 1,
        ])->assertCreated();

        // The supplier puts the price up afterwards.
        $this->apiPatch($this->admin, "/api/products/{$this->product->id}", ['cost_price' => '200.00'])
            ->assertOk();

        // The sold line keeps the cost it was sold at, so profit stays right.
        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoice['id'],
            'unit_cost' => '88.00',
        ]);
    }

    public function test_a_discount_reduces_the_total_and_is_logged(): void
    {
        $invoice = $this->invoice();

        $after = $this->apiPost($this->admin, "/api/invoices/{$invoice['id']}/discount", [
            'percent' => '10', 'reason' => 'Opening week promotion',
        ])->assertOk()->json();

        $this->assertSame('15.00', $after['discount_amount']);
        $this->assertSame('135.00', $after['total_amount']);
        $this->assertReconciles($after);

        $actions = collect($this->apiGet($this->admin, '/api/audit')->json())->pluck('action');
        $this->assertContains('invoice_discount', $actions->all());
    }

    public function test_a_flat_discount_is_accepted_and_never_exceeds_what_is_owed(): void
    {
        $invoice = $this->invoice();

        $after = $this->apiPost($this->admin, "/api/invoices/{$invoice['id']}/discount", [
            'amount' => '9999', 'reason' => 'goodwill after an outage',
        ])->assertOk()->json();

        // Capped at the subtotal: an invoice must never become a payout.
        $this->assertSame('150.00', $after['discount_amount']);
        $this->assertSame('0.00', $after['total_amount']);
    }

    public function test_a_discount_reason_shorter_than_three_characters_is_422(): void
    {
        $invoice = $this->invoice();

        $this->apiPost($this->admin, "/api/invoices/{$invoice['id']}/discount", [
            'percent' => '10', 'reason' => 'x',
        ])->assertStatus(422);
    }

    public function test_voiding_excludes_the_invoice_from_revenue_and_analytics(): void
    {
        $keep = $this->invoice();
        $kill = $this->invoice();

        $before = $this->apiGet($this->admin, '/api/analytics/profit')->json();
        $this->assertSame('300.00', $before['total_revenue']);

        $this->apiPost($this->admin, "/api/invoices/{$kill['id']}/void", [
            'reason' => 'rung up on the wrong booth',
        ])->assertOk()->assertJson(['status' => 'void']);

        $after = $this->apiGet($this->admin, '/api/analytics/profit')->json();
        $this->assertSame('150.00', $after['total_revenue']);

        // Kept for the record, not deleted.
        $this->assertDatabaseHas('invoices', ['id' => $kill['id'], 'status' => 'void']);
        $this->assertSame($keep['id'], $keep['id']);
    }

    public function test_a_void_reason_shorter_than_three_characters_is_422(): void
    {
        $invoice = $this->invoice();

        $this->apiPost($this->admin, "/api/invoices/{$invoice['id']}/void", ['reason' => 'x'])
            ->assertStatus(422);

        $this->assertDatabaseHas('invoices', ['id' => $invoice['id'], 'status' => 'active']);
    }

    public function test_a_voided_invoice_cannot_be_edited_further(): void
    {
        $invoice = $this->invoice();

        $this->apiPost($this->admin, "/api/invoices/{$invoice['id']}/void", ['reason' => 'wrong booth'])
            ->assertOk();

        $this->apiPost($this->staff, "/api/invoices/{$invoice['id']}/items", [
            'product_id' => $this->product->id, 'quantity' => 1,
        ])->assertStatus(400);
    }

    public function test_a_cancelled_session_produces_no_invoice(): void
    {
        $customer = $this->makeCustomer($this->cafe);
        $session = $this->makeSession($this->cafe, $this->station, $customer, 45);

        $this->apiPost($this->staff, "/api/sessions/{$session->id}/cancel")
            ->assertOk()->assertJson(['status' => 'cancelled']);

        $this->assertDatabaseMissing('invoices', ['session_id' => $session->id]);

        // And the device is free again.
        $row = collect($this->apiGet($this->staff, '/api/sessions/active')->json())
            ->firstWhere('station_id', $this->station->id);

        $this->assertSame('free', $row['status']);
    }

    public function test_the_audit_log_attributes_each_action_to_the_right_actor(): void
    {
        $invoice = $this->invoice();

        $this->apiPost($this->staff, "/api/invoices/{$invoice['id']}/items", [
            'product_id' => $this->product->id, 'quantity' => 1,
        ])->assertCreated();

        $this->apiPost($this->admin, "/api/invoices/{$invoice['id']}/void", ['reason' => 'wrong booth'])
            ->assertOk();

        $events = collect($this->apiGet($this->admin, '/api/audit')->json());

        $itemEvent = $events->firstWhere('action', 'invoice_item_add');
        $voidEvent = $events->firstWhere('action', 'invoice_void');

        $this->assertSame('staff@example.com', $itemEvent['actor_email']);
        $this->assertSame('staff', $itemEvent['actor_role']);
        $this->assertSame('admin@example.com', $voidEvent['actor_email']);
        $this->assertSame('admin', $voidEvent['actor_role']);
    }

    public function test_an_anonymous_qr_checkin_is_logged_as_the_customer(): void
    {
        config()->set('cafetrack.require_qr_token', false);

        $this->postJson("/api/checkin/{$this->station->id}", [
            'name' => 'Rafi Ahmed', 'phone_or_id' => '01711111111',
        ])->assertCreated();

        $event = collect($this->apiGet($this->admin, '/api/audit')->json())
            ->firstWhere('action', 'session_start');

        $this->assertSame('customer (QR)', $event['actor_email']);
        $this->assertSame('public', $event['actor_role']);
    }

    public function test_the_csv_export_has_exactly_the_agreed_columns(): void
    {
        $this->invoice();

        $response = $this->apiGet($this->admin, '/api/invoices/export.csv');
        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $header = str_getcsv(strtok($csv, "\n"));

        $this->assertSame([
            'invoice_id', 'created_at', 'station', 'customer', 'duration_minutes',
            'controllers', 'hourly_rate', 'total_amount', 'payment_method', 'payment_status',
        ], $header);
    }

    public function test_the_pdf_receipt_renders(): void
    {
        $invoice = $this->invoice();

        $this->apiPost($this->staff, "/api/invoices/{$invoice['id']}/items", [
            'product_id' => $this->product->id, 'quantity' => 2,
        ])->assertCreated();

        $response = $this->apiGet($this->admin, "/api/invoices/{$invoice['id']}/pdf");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }
}
