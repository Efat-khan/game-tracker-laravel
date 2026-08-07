<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Cafe;
use App\Models\Customer;
use App\Models\Station;
use App\Services\SettingsService;
use Tests\TestCase;

/**
 * §5.4 — the cash drawer.
 *
 * The rule everything here turns on: ONLY CASH touches the drawer. Phone
 * payments and wallet spends are excluded on purpose — that money never
 * entered the till.
 */
class ShiftTest extends TestCase
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

    private function openShift(string $float = '1000'): array
    {
        return $this->apiPost($this->staff, '/api/shifts/open', ['opening_float' => $float])
            ->assertCreated()->json();
    }

    /** A 60-minute session at 150/hr, settled by $method. */
    private function sell(string $method = 'cash'): array
    {
        $session = $this->makeSession($this->cafe, $this->station, $this->customer, 60);

        $invoice = $this->apiPost($this->staff, "/api/checkout/{$session->id}")->assertCreated()->json();

        $this->apiPatch($this->staff, "/api/invoices/{$invoice['id']}", [
            'payment_status' => 'paid', 'payment_method' => $method,
        ])->assertOk();

        return $invoice;
    }

    public function test_only_one_shift_can_be_open_at_a_time(): void
    {
        $this->openShift();

        $this->apiPost($this->staff, '/api/shifts/open', ['opening_float' => '500'])->assertStatus(409);
    }

    public function test_a_cash_sale_enters_the_drawer(): void
    {
        $this->openShift('1000');
        $this->sell('cash');

        $shift = $this->apiGet($this->staff, '/api/shifts/current')->json();

        $this->assertSame('150.00', $shift['totals']['cash_sales']);
        // 1000 float + 150 cash
        $this->assertSame('1150.00', $shift['expected_cash']);
    }

    public function test_a_phone_sale_does_not_enter_the_drawer(): void
    {
        $this->openShift('1000');
        $this->sell('phone_payment');

        $shift = $this->apiGet($this->staff, '/api/shifts/current')->json();

        $this->assertSame('150.00', $shift['totals']['phone_sales']);
        $this->assertSame('0.00', $shift['totals']['cash_sales']);
        // The drawer is untouched — that money never arrived in it.
        $this->assertSame('1000.00', $shift['expected_cash']);
    }

    public function test_a_wallet_sale_does_not_enter_the_drawer(): void
    {
        $this->openShift('1000');

        // The credit was paid for at top-up time and counted in the drawer then.
        $this->apiPost($this->staff, "/api/customers/{$this->customer->id}/adjust", [
            'amount' => '500', 'reason' => 'opening credit for the test',
        ]);
        $this->apiPost($this->admin, "/api/customers/{$this->customer->id}/adjust", [
            'amount' => '500', 'reason' => 'opening credit for the test',
        ])->assertOk();

        $session = $this->makeSession($this->cafe, $this->station, $this->customer, 60);
        $invoice = $this->apiPost($this->staff, "/api/checkout/{$session->id}")->assertCreated()->json();

        $this->apiPost($this->staff, "/api/invoices/{$invoice['id']}/pay-wallet")->assertOk();

        $shift = $this->apiGet($this->staff, '/api/shifts/current')->json();

        $this->assertSame('150.00', $shift['totals']['wallet_sales']);
        $this->assertSame('0.00', $shift['totals']['cash_sales']);
        $this->assertSame('1000.00', $shift['expected_cash']);
    }

    public function test_topups_follow_their_own_payment_method(): void
    {
        $this->openShift('1000');

        $this->apiPost($this->staff, "/api/customers/{$this->customer->id}/topup", [
            'amount' => '500', 'payment_method' => 'cash',
        ])->assertOk();

        $this->apiPost($this->staff, "/api/customers/{$this->customer->id}/topup", [
            'amount' => '300', 'payment_method' => 'phone_payment',
        ])->assertOk();

        $shift = $this->apiGet($this->staff, '/api/shifts/current')->json();

        $this->assertSame('500.00', $shift['totals']['cash_topups']);
        $this->assertSame('300.00', $shift['totals']['phone_topups']);
        // Only the cash one reaches the drawer.
        $this->assertSame('1500.00', $shift['expected_cash']);
    }

    public function test_expected_cash_follows_the_formula(): void
    {
        $shift = $this->openShift('1000');

        $this->sell('cash');          // +150
        $this->sell('phone_payment'); // ignored

        $this->apiPost($this->staff, "/api/customers/{$this->customer->id}/topup", [
            'amount' => '500', 'payment_method' => 'cash',
        ])->assertOk();               // +500

        $this->apiPost($this->staff, "/api/shifts/{$shift['id']}/cash", [
            'kind' => 'in', 'amount' => '200', 'reason' => 'change float top-up',
        ])->assertCreated();          // +200

        $this->apiPost($this->staff, "/api/shifts/{$shift['id']}/cash", [
            'kind' => 'out', 'amount' => '350', 'reason' => 'bought napkins',
        ])->assertCreated();          // -350

        $current = $this->apiGet($this->staff, '/api/shifts/current')->json();

        // 1000 + 150 + 500 + 200 - 350
        $this->assertSame('1500.00', $current['expected_cash']);
    }

    public function test_variance_is_recorded_at_close(): void
    {
        $shift = $this->openShift('1000');
        $this->sell('cash');

        $closed = $this->apiPost($this->staff, "/api/shifts/{$shift['id']}/close", [
            'counted_cash' => '1100', 'note' => 'fifty short',
        ])->assertOk()->json();

        $this->assertSame('closed', $closed['status']);
        $this->assertSame('1150.00', $closed['expected_cash']);
        $this->assertSame('1100.00', $closed['counted_cash']);
        $this->assertSame('-50.00', $closed['variance']);
    }

    public function test_a_surplus_is_recorded_as_a_positive_variance(): void
    {
        $shift = $this->openShift('1000');

        $closed = $this->apiPost($this->staff, "/api/shifts/{$shift['id']}/close", [
            'counted_cash' => '1025',
        ])->assertOk()->json();

        $this->assertSame('25.00', $closed['variance']);
    }

    public function test_a_closed_shift_is_frozen_against_a_later_void(): void
    {
        $shift = $this->openShift('1000');
        $invoice = $this->sell('cash');

        $closed = $this->apiPost($this->staff, "/api/shifts/{$shift['id']}/close", [
            'counted_cash' => '1150',
        ])->assertOk()->json();

        $this->assertSame('1150.00', $closed['expected_cash']);
        $this->assertSame('0.00', $closed['variance']);

        // The next day, someone voids a sale that shift took.
        $this->apiPost($this->admin, "/api/invoices/{$invoice['id']}/void", [
            'reason' => 'rung up twice',
        ])->assertOk();

        $after = $this->apiGet($this->staff, "/api/shifts/{$shift['id']}")->json();

        // The signed-off reconciliation is untouched.
        $this->assertSame('1150.00', $after['expected_cash']);
        $this->assertSame('0.00', $after['variance']);
    }

    public function test_a_closed_shift_cannot_be_closed_again_or_take_cash(): void
    {
        $shift = $this->openShift();

        $this->apiPost($this->staff, "/api/shifts/{$shift['id']}/close", ['counted_cash' => '1000'])->assertOk();

        $this->apiPost($this->staff, "/api/shifts/{$shift['id']}/close", ['counted_cash' => '900'])
            ->assertStatus(409);

        $this->apiPost($this->staff, "/api/shifts/{$shift['id']}/cash", [
            'kind' => 'in', 'amount' => '100', 'reason' => 'sneaking it in',
        ])->assertStatus(409);
    }

    public function test_takings_land_in_the_shift_that_collected_them(): void
    {
        // Session starts with no shift open at all.
        $session = $this->makeSession($this->cafe, $this->station, $this->customer, 60);
        $invoice = $this->apiPost($this->staff, "/api/checkout/{$session->id}")->assertCreated()->json();

        // The shift opens afterwards, and takes the money.
        $shift = $this->openShift('0');

        $this->apiPatch($this->staff, "/api/invoices/{$invoice['id']}", [
            'payment_status' => 'paid', 'payment_method' => 'cash',
        ])->assertOk();

        $current = $this->apiGet($this->staff, '/api/shifts/current')->json();

        $this->assertSame('150.00', $current['totals']['cash_sales']);
        $this->assertDatabaseHas('invoices', ['id' => $invoice['id'], 'shift_id' => $shift['id']]);
    }

    public function test_two_cafes_shifts_do_not_mix(): void
    {
        $other = $this->makeCafe('Other Cafe');
        $otherStaff = $this->makeUser($other, 'staff', 'otherstaff@example.com');

        $this->openShift('1000');
        $this->sell('cash');

        $this->apiPost($otherStaff, '/api/shifts/open', ['opening_float' => '2000'])->assertCreated();

        $theirs = $this->apiGet($otherStaff, '/api/shifts/current')->json();

        $this->assertSame('0.00', $theirs['totals']['cash_sales']);
        $this->assertSame('2000.00', $theirs['expected_cash']);
    }

    public function test_the_staff_rollup_counts_closed_shifts_only(): void
    {
        $shift = $this->openShift('1000');
        $this->sell('cash');

        // While it is still open it contributes nothing.
        $this->assertSame([], $this->apiGet($this->admin, '/api/analytics/staff')->json());

        $this->apiPost($this->staff, "/api/shifts/{$shift['id']}/close", ['counted_cash' => '1140'])->assertOk();

        $rollup = $this->apiGet($this->admin, '/api/analytics/staff')->json();

        $this->assertCount(1, $rollup);
        $this->assertSame('staff@example.com', $rollup[0]['email']);
        $this->assertSame(1, $rollup[0]['shifts']);
        $this->assertSame('150.00', $rollup[0]['total_sales']);
        $this->assertSame('150.00', $rollup[0]['cash_collected']);
        $this->assertSame('-10.00', $rollup[0]['total_variance']);
    }

    public function test_a_cash_movement_needs_a_reason(): void
    {
        $shift = $this->openShift();

        $this->apiPost($this->staff, "/api/shifts/{$shift['id']}/cash", [
            'kind' => 'out', 'amount' => '100', 'reason' => 'x',
        ])->assertStatus(422);

        $this->apiPost($this->staff, "/api/shifts/{$shift['id']}/cash", [
            'kind' => 'sideways', 'amount' => '100', 'reason' => 'valid reason',
        ])->assertStatus(422);
    }

    public function test_current_returns_null_when_no_shift_is_open(): void
    {
        $response = $this->apiGet($this->staff, '/api/shifts/current')->assertOk();

        // A literal JSON null, so the client can tell "no shift open" apart from
        // "a shift with no fields". (TestResponse::json() cannot represent a
        // null body, so this asserts on the raw content.)
        $this->assertSame('null', $response->getContent());
    }
}
