<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Cafe;
use App\Models\Customer;
use App\Models\MembershipTier;
use App\Models\Package;
use App\Models\Station;
use App\Services\SettingsService;
use Tests\TestCase;

/** §5.3 — prepaid credit and membership tiers. */
class WalletTest extends TestCase
{
    private Cafe $cafe;

    private AdminUser $admin;

    private AdminUser $staff;

    private Station $station;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cafe = $this->makeCafe();
        $this->admin = $this->makeUser($this->cafe, 'admin', 'admin@example.com');
        $this->staff = $this->makeUser($this->cafe, 'staff', 'staff@example.com');
        $this->station = $this->makeStation($this->cafe);
        $this->customer = $this->makeCustomer($this->cafe);

        app(SettingsService::class)->put($this->cafe->id, [
            'billing_round_minutes' => 1, 'round_amount_to' => 1,
        ]);
    }

    /** 60 min at 150/hr = 150.00. */
    private function invoice(): array
    {
        $session = $this->makeSession($this->cafe, $this->station, $this->customer, 60);

        return $this->apiPost($this->admin, "/api/checkout/{$session->id}")->assertCreated()->json();
    }

    private function tiers(): void
    {
        foreach ([['Bronze', '0.00', '0.00'], ['Silver', '3000.00', '5.00'], ['Gold', '10000.00', '10.00']] as $t) {
            MembershipTier::create([
                'cafe_id' => $this->cafe->id, 'name' => $t[0], 'min_spend' => $t[1],
                'discount_percent' => $t[2], 'is_active' => true, 'created_at' => now(),
            ]);
        }
    }

    /* --------------------------------------------------------------- top-up */

    public function test_a_package_topup_credits_the_bonus_not_the_price(): void
    {
        $package = Package::create([
            'cafe_id' => $this->cafe->id, 'name' => 'Regular',
            'price' => '1000.00', 'credit' => '1150.00', 'created_at' => now(),
        ]);

        $result = $this->apiPost($this->staff, "/api/customers/{$this->customer->id}/topup", [
            'package_id' => $package->id, 'payment_method' => 'cash',
        ])->assertOk()->json();

        // Pay 1000, play 1150 — the bonus is what drives the upfront cash.
        $this->assertSame('1150.00', $result['balance']);
        $this->assertSame('1150.00', $result['transaction']['amount']);
        $this->assertSame('topup', $result['transaction']['kind']);
    }

    public function test_a_custom_amount_topup_credits_exactly_that_amount(): void
    {
        $result = $this->apiPost($this->staff, "/api/customers/{$this->customer->id}/topup", [
            'amount' => '500', 'payment_method' => 'phone_payment',
        ])->assertOk()->json();

        $this->assertSame('500.00', $result['balance']);
        $this->assertSame('phone_payment', $result['transaction']['payment_method']);
    }

    public function test_every_balance_change_writes_a_ledger_row_with_balance_after(): void
    {
        $this->apiPost($this->staff, "/api/customers/{$this->customer->id}/topup", ['amount' => '500'])->assertOk();
        $this->apiPost($this->staff, "/api/customers/{$this->customer->id}/topup", ['amount' => '300'])->assertOk();

        $ledger = $this->apiGet($this->staff, "/api/customers/{$this->customer->id}/wallet")->json();

        $this->assertSame('800.00', $ledger['balance']);
        $this->assertCount(2, $ledger['transactions']);
        // Newest first.
        $this->assertSame('800.00', $ledger['transactions'][0]['balance_after']);
        $this->assertSame('500.00', $ledger['transactions'][1]['balance_after']);
    }

    public function test_a_manual_adjustment_can_take_credit_away(): void
    {
        $this->apiPost($this->staff, "/api/customers/{$this->customer->id}/topup", ['amount' => '500'])->assertOk();

        $result = $this->apiPost($this->admin, "/api/customers/{$this->customer->id}/adjust", [
            'amount' => '-200', 'reason' => 'double-counted a top-up',
        ])->assertOk()->json();

        $this->assertSame('300.00', $result['balance']);
        $this->assertSame('adjust', $result['transaction']['kind']);
    }

    /* -------------------------------------------------------- paying by wallet */

    public function test_paying_from_the_wallet_requires_sufficient_balance(): void
    {
        $invoice = $this->invoice();

        $this->apiPost($this->staff, "/api/customers/{$this->customer->id}/topup", ['amount' => '100'])->assertOk();

        // 100 available, 150 owed.
        $this->apiPost($this->staff, "/api/invoices/{$invoice['id']}/pay-wallet")->assertStatus(400);

        $this->assertDatabaseHas('invoices', ['id' => $invoice['id'], 'payment_status' => 'unpaid']);
    }

    public function test_paying_from_the_wallet_spends_the_balance_and_sets_the_method(): void
    {
        $invoice = $this->invoice();

        $this->apiPost($this->staff, "/api/customers/{$this->customer->id}/topup", ['amount' => '500'])->assertOk();

        $this->apiPost($this->staff, "/api/invoices/{$invoice['id']}/pay-wallet")
            ->assertOk()
            ->assertJson(['payment_status' => 'paid', 'payment_method' => 'wallet']);

        $ledger = $this->apiGet($this->staff, "/api/customers/{$this->customer->id}/wallet")->json();

        $this->assertSame('350.00', $ledger['balance']);
        $this->assertSame('spend', $ledger['transactions'][0]['kind']);
        $this->assertSame('-150.00', $ledger['transactions'][0]['amount']);
    }

    public function test_wallet_is_never_accepted_as_a_payment_method_from_a_client(): void
    {
        $invoice = $this->invoice();

        $this->apiPatch($this->admin, "/api/invoices/{$invoice['id']}", [
            'payment_status' => 'paid', 'payment_method' => 'wallet',
        ])->assertStatus(422);
    }

    public function test_voiding_a_wallet_paid_invoice_refunds_the_balance(): void
    {
        $invoice = $this->invoice();

        $this->apiPost($this->staff, "/api/customers/{$this->customer->id}/topup", ['amount' => '500'])->assertOk();
        $this->apiPost($this->staff, "/api/invoices/{$invoice['id']}/pay-wallet")->assertOk();

        $this->apiPost($this->admin, "/api/invoices/{$invoice['id']}/void", [
            'reason' => 'charged the wrong customer',
        ])->assertOk();

        $ledger = $this->apiGet($this->staff, "/api/customers/{$this->customer->id}/wallet")->json();

        $this->assertSame('500.00', $ledger['balance']);
        $this->assertSame('refund', $ledger['transactions'][0]['kind']);
        $this->assertSame('150.00', $ledger['transactions'][0]['amount']);
    }

    /* ------------------------------------------------------- lifetime spend */

    public function test_lifetime_spend_excludes_unpaid_invoices(): void
    {
        $this->invoice(); // left unpaid

        $customer = $this->apiGet($this->admin, "/api/customers/{$this->customer->id}")->json();

        $this->assertSame('0.00', $customer['lifetime_spend']);
    }

    public function test_lifetime_spend_excludes_voided_invoices(): void
    {
        $paid = $this->invoice();
        $this->apiPatch($this->admin, "/api/invoices/{$paid['id']}", [
            'payment_status' => 'paid', 'payment_method' => 'cash',
        ])->assertOk();

        $voided = $this->invoice();
        $this->apiPatch($this->admin, "/api/invoices/{$voided['id']}", [
            'payment_status' => 'paid', 'payment_method' => 'cash',
        ])->assertOk();
        $this->apiPost($this->admin, "/api/invoices/{$voided['id']}/void", ['reason' => 'wrong booth'])->assertOk();

        $customer = $this->apiGet($this->admin, "/api/customers/{$this->customer->id}")->json();

        // Only the one surviving paid invoice counts — the figure is computed,
        // not stored, so voiding cannot leave it stale.
        $this->assertSame('150.00', $customer['lifetime_spend']);
    }

    /* ----------------------------------------------------------------- tiers */

    public function test_the_correct_tier_applies_at_its_threshold(): void
    {
        $this->tiers();

        // Spend 3000 to reach Silver.
        for ($i = 0; $i < 20; $i++) {
            $invoice = $this->invoice();
            $this->apiPatch($this->admin, "/api/invoices/{$invoice['id']}", [
                'payment_status' => 'paid', 'payment_method' => 'cash',
            ])->assertOk();
        }

        $customer = $this->apiGet($this->admin, "/api/customers/{$this->customer->id}")->json();

        $this->assertSame('3000.00', $customer['lifetime_spend']);
        $this->assertSame('Silver', $customer['tier_name']);
    }

    public function test_the_tier_discount_reason_trims_trailing_zeros(): void
    {
        $this->tiers();

        for ($i = 0; $i < 20; $i++) {
            $invoice = $this->invoice();
            $this->apiPatch($this->admin, "/api/invoices/{$invoice['id']}", [
                'payment_status' => 'paid', 'payment_method' => 'cash',
            ])->assertOk();
        }

        // The next checkout picks the tier up automatically.
        $next = $this->invoice();

        // "Silver member 5%", not "5.00%".
        $this->assertSame('Silver member 5%', $next['discount_reason']);
        $this->assertSame('7.50', $next['discount_amount']);
        $this->assertSame('142.50', $next['total_amount']);
    }

    public function test_a_zero_percent_tier_leaves_the_invoice_clean(): void
    {
        $this->tiers();

        // Bronze is reached at zero spend but discounts nothing.
        $invoice = $this->invoice();

        $this->assertSame('0.00', $invoice['discount_amount']);
        $this->assertNull($invoice['discount_reason']);
    }

    public function test_a_customer_below_every_threshold_gets_no_tier(): void
    {
        MembershipTier::create([
            'cafe_id' => $this->cafe->id, 'name' => 'Silver', 'min_spend' => '3000.00',
            'discount_percent' => '5.00', 'is_active' => true, 'created_at' => now(),
        ]);

        $customer = $this->apiGet($this->admin, "/api/customers/{$this->customer->id}")->json();

        $this->assertNull($customer['tier_name']);
    }
}
