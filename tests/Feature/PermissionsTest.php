<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Cafe;
use App\Models\Station;
use App\Services\SettingsService;
use Tests\TestCase;

/** §3 — the staff/admin line, tested from both sides. */
class PermissionsTest extends TestCase
{
    private Cafe $cafe;

    private AdminUser $admin;

    private AdminUser $staff;

    private Station $station;

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
    }

    private function invoice(): array
    {
        $customer = $this->makeCustomer($this->cafe);
        $session = $this->makeSession($this->cafe, $this->station, $customer, 60);

        return $this->apiPost($this->admin, "/api/checkout/{$session->id}")->assertCreated()->json();
    }

    /* ------------------------------------------------- staff are blocked */

    public function test_staff_cannot_create_update_or_delete_a_station(): void
    {
        $this->apiPost($this->staff, '/api/stations', [
            'name' => 'Sneaky', 'type' => 'PS5', 'hourly_rate' => '10',
        ])->assertForbidden();

        $this->apiPatch($this->staff, "/api/stations/{$this->station->id}", ['hourly_rate' => '1'])
            ->assertForbidden();

        $this->apiDelete($this->staff, "/api/stations/{$this->station->id}")->assertForbidden();
    }

    public function test_staff_cannot_change_settings(): void
    {
        $this->apiPatch($this->staff, '/api/settings', ['billing_round_minutes' => 60])->assertForbidden();

        // They may still read them — the dashboard needs the billing rules.
        $this->apiGet($this->staff, '/api/settings')->assertOk();
    }

    public function test_staff_cannot_manage_accounts(): void
    {
        $this->apiGet($this->staff, '/api/staff')->assertForbidden();

        $this->apiPost($this->staff, '/api/staff', [
            'email' => 'new@example.com', 'password' => 'secret123', 'role' => 'admin',
        ])->assertForbidden();

        $this->apiPost($this->staff, "/api/staff/{$this->admin->id}/revoke")->assertForbidden();
        $this->apiDelete($this->staff, "/api/staff/{$this->admin->id}")->assertForbidden();
    }

    public function test_staff_cannot_apply_a_discount(): void
    {
        $invoice = $this->invoice();

        $this->apiPost($this->staff, "/api/invoices/{$invoice['id']}/discount", [
            'percent' => '50', 'reason' => 'friend of mine',
        ])->assertForbidden();

        $this->assertDatabaseHas('invoices', ['id' => $invoice['id'], 'discount_amount' => '0.00']);
    }

    public function test_staff_cannot_void_an_invoice(): void
    {
        $invoice = $this->invoice();

        $this->apiPost($this->staff, "/api/invoices/{$invoice['id']}/void", ['reason' => 'oops'])
            ->assertForbidden();

        $this->assertDatabaseHas('invoices', ['id' => $invoice['id'], 'status' => 'active']);
    }

    public function test_staff_cannot_make_a_manual_balance_adjustment(): void
    {
        $customer = $this->makeCustomer($this->cafe);

        $this->apiPost($this->staff, "/api/customers/{$customer->id}/adjust", [
            'amount' => '5000', 'reason' => 'a gift to myself',
        ])->assertForbidden();

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'balance' => '0.00']);
    }

    public function test_staff_cannot_read_the_activity_log(): void
    {
        $this->apiGet($this->staff, '/api/audit')->assertForbidden();
    }

    public function test_staff_cannot_manage_products_packages_or_tiers(): void
    {
        $this->apiPost($this->staff, '/api/products', ['name' => 'X', 'price' => '10'])->assertForbidden();
        $this->apiPost($this->staff, '/api/packages', ['name' => 'X', 'price' => '10', 'credit' => '12'])->assertForbidden();
        $this->apiPost($this->staff, '/api/tiers', ['name' => 'X'])->assertForbidden();

        // But they may read all three — they sell from the catalogue.
        $this->apiGet($this->staff, '/api/products')->assertOk();
        $this->apiGet($this->staff, '/api/packages')->assertOk();
        $this->apiGet($this->staff, '/api/tiers')->assertOk();
    }

    public function test_staff_cannot_see_the_per_staff_analytics_rollup(): void
    {
        $this->apiGet($this->staff, '/api/analytics/staff')->assertForbidden();

        // The rest of analytics is open to them.
        $this->apiGet($this->staff, '/api/analytics/profit')->assertOk();
    }

    /* --------------------------------------------------- staff are allowed */

    public function test_staff_can_mark_an_unpaid_invoice_paid(): void
    {
        $invoice = $this->invoice();

        $this->apiPatch($this->staff, "/api/invoices/{$invoice['id']}", [
            'payment_status' => 'paid', 'payment_method' => 'cash',
        ])->assertOk()->assertJson(['payment_status' => 'paid', 'payment_method' => 'cash']);
    }

    public function test_staff_cannot_change_the_payment_method_of_a_paid_invoice(): void
    {
        $invoice = $this->invoice();

        $this->apiPatch($this->admin, "/api/invoices/{$invoice['id']}", [
            'payment_status' => 'paid', 'payment_method' => 'cash',
        ])->assertOk();

        $this->apiPatch($this->staff, "/api/invoices/{$invoice['id']}", [
            'payment_method' => 'phone_payment',
        ])->assertForbidden();

        $this->assertDatabaseHas('invoices', ['id' => $invoice['id'], 'payment_method' => 'cash']);
    }

    public function test_staff_cannot_revert_a_paid_invoice_to_unpaid(): void
    {
        $invoice = $this->invoice();

        $this->apiPatch($this->admin, "/api/invoices/{$invoice['id']}", [
            'payment_status' => 'paid', 'payment_method' => 'cash',
        ])->assertOk();

        $this->apiPatch($this->staff, "/api/invoices/{$invoice['id']}", ['payment_status' => 'unpaid'])
            ->assertForbidden();

        $this->assertDatabaseHas('invoices', ['id' => $invoice['id'], 'payment_status' => 'paid']);
    }

    public function test_an_admin_can_revert_a_paid_invoice_to_unpaid(): void
    {
        $invoice = $this->invoice();

        $this->apiPatch($this->admin, "/api/invoices/{$invoice['id']}", [
            'payment_status' => 'paid', 'payment_method' => 'cash',
        ])->assertOk();

        $this->apiPatch($this->admin, "/api/invoices/{$invoice['id']}", ['payment_status' => 'unpaid'])
            ->assertOk()->assertJson(['payment_status' => 'unpaid']);
    }

    public function test_staff_can_run_the_floor(): void
    {
        $customer = $this->makeCustomer($this->cafe);
        $session = $this->makeSession($this->cafe, $this->station, $customer, 30);

        $this->apiGet($this->staff, '/api/sessions/active')->assertOk();
        $this->apiPost($this->staff, "/api/checkout/{$session->id}")->assertCreated();
        $this->apiPost($this->staff, '/api/shifts/open', ['opening_float' => '1000'])->assertCreated();
        $this->apiPost($this->staff, "/api/customers/{$customer->id}/topup", ['amount' => '500'])->assertOk();
    }

    /* ------------------------------------------------ last-admin protection */

    public function test_the_last_admin_cannot_be_demoted(): void
    {
        $this->apiPatch($this->admin, "/api/staff/{$this->admin->id}", ['role' => 'staff'])
            ->assertStatus(400);

        $this->assertDatabaseHas('admin_users', ['id' => $this->admin->id, 'role' => 'admin']);
    }

    public function test_the_last_admin_cannot_be_deleted(): void
    {
        $this->apiDelete($this->admin, "/api/staff/{$this->admin->id}")->assertStatus(400);

        $this->assertDatabaseHas('admin_users', ['id' => $this->admin->id]);
    }

    public function test_an_admin_can_be_demoted_when_another_admin_remains(): void
    {
        $second = $this->makeUser($this->cafe, 'admin', 'second@example.com');

        $this->apiPatch($this->admin, "/api/staff/{$second->id}", ['role' => 'staff'])->assertOk();

        $this->assertDatabaseHas('admin_users', ['id' => $second->id, 'role' => 'staff']);
    }

    public function test_an_unauthenticated_request_is_401_not_403(): void
    {
        $this->getJson('/api/stations')->assertUnauthorized();
    }
}
