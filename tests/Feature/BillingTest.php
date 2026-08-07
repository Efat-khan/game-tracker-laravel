<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Cafe;
use App\Models\Customer;
use App\Models\Station;
use App\Support\Money;
use App\Services\SettingsService;
use Tests\TestCase;

/**
 * §5.1, tested hard. Every case here is a rule that, if it slips, quietly
 * mis-bills a real customer.
 */
class BillingTest extends TestCase
{
    private Cafe $cafe;

    private AdminUser $admin;

    private Station $station;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cafe = $this->makeCafe();
        $this->admin = $this->makeUser($this->cafe, 'admin');
        $this->station = $this->makeStation($this->cafe);
        $this->customer = $this->makeCustomer($this->cafe);
    }

    private function setSettings(array $values): void
    {
        app(SettingsService::class)->put($this->cafe->id, $values);
    }

    private function billFor(int $minutesAgo, array $sessionAttributes = []): array
    {
        $session = $this->makeSession(
            $this->cafe, $this->station, $this->customer, $minutesAgo, $sessionAttributes,
        );

        return $this->apiPost($this->admin, "/api/checkout/{$session->id}")
            ->assertCreated()
            ->json();
    }

    /* ---------------------------------------------------------------- time */

    public function test_a_three_minute_session_bills_as_one_whole_block(): void
    {
        $this->setSettings(['billing_round_minutes' => 15, 'round_amount_to' => 1]);

        // 200/hr so a whole block is a whole number and the amount-rounding
        // step cannot muddy what this test is about.
        $invoice = $this->billFor(3, ['hourly_rate_snapshot' => '200.00']);

        $this->assertSame(15, $invoice['duration_minutes']);
        $this->assertSame('50.00', $invoice['session_amount']);
    }

    public function test_twenty_two_minutes_on_a_fifteen_minute_block_bills_as_thirty(): void
    {
        $this->setSettings(['billing_round_minutes' => 15, 'round_amount_to' => 1]);

        $this->assertSame(30, $this->billFor(22)['duration_minutes']);
    }

    public function test_a_block_of_one_bills_per_minute(): void
    {
        $this->setSettings(['billing_round_minutes' => 1, 'round_amount_to' => 1]);

        $this->assertSame(22, $this->billFor(22)['duration_minutes']);
    }

    public function test_an_exact_multiple_of_the_block_is_not_rounded_up(): void
    {
        $this->setSettings(['billing_round_minutes' => 15, 'round_amount_to' => 1]);

        $this->assertSame(30, $this->billFor(30)['duration_minutes']);
    }

    public function test_a_session_ended_within_a_minute_still_bills_one_block(): void
    {
        $this->setSettings(['billing_round_minutes' => 15, 'round_amount_to' => 1]);

        $this->assertSame(15, $this->billFor(0)['duration_minutes']);
    }

    /* --------------------------------------------------------------- money */

    public function test_two_hundred_and_two_rounds_down_to_two_hundred(): void
    {
        // 60 min at 202/hr = 202.00 gross, step 5 -> 200
        $this->setSettings(['billing_round_minutes' => 1, 'round_amount_to' => 5]);
        $this->station->update(['hourly_rate' => '202.00']);

        $invoice = $this->billFor(60, ['hourly_rate_snapshot' => '202.00']);

        $this->assertSame('200.00', $invoice['session_amount']);
    }

    public function test_four_hundred_point_five_six_rounds_down_to_four_hundred(): void
    {
        $this->setSettings(['billing_round_minutes' => 1, 'round_amount_to' => 5]);

        $invoice = $this->billFor(60, ['hourly_rate_snapshot' => '400.56']);

        $this->assertSame('400.00', $invoice['session_amount']);
    }

    public function test_a_half_step_rounds_up(): void
    {
        // 102.50 is exactly half a 5-step above 100 -> 105
        $this->setSettings(['billing_round_minutes' => 1, 'round_amount_to' => 5]);

        $invoice = $this->billFor(60, ['hourly_rate_snapshot' => '102.50']);

        $this->assertSame('105.00', $invoice['session_amount']);
    }

    public function test_a_nonzero_bill_never_rounds_away_to_zero(): void
    {
        // 1 min at 60/hr = 1.00 gross. Rounding to the nearest 5 would give 0,
        // which would hand out free play; it must floor at one step instead.
        $this->setSettings(['billing_round_minutes' => 1, 'round_amount_to' => 5]);

        $invoice = $this->billFor(1, ['hourly_rate_snapshot' => '60.00']);

        $this->assertSame('5.00', $invoice['session_amount']);
    }

    public function test_a_step_of_one_leaves_the_amount_alone(): void
    {
        $this->setSettings(['billing_round_minutes' => 1, 'round_amount_to' => 1]);

        $invoice = $this->billFor(60, ['hourly_rate_snapshot' => '202.00']);

        $this->assertSame('202.00', $invoice['session_amount']);
    }

    /* --------------------------------------------------- controllers/rates */

    public function test_three_controllers_at_150_plus_50_bills_250_an_hour(): void
    {
        $this->setSettings(['billing_round_minutes' => 1, 'round_amount_to' => 1]);

        $response = $this->apiPost($this->admin, "/api/checkin/{$this->station->id}", [
            'name' => 'Rafi Ahmed',
            'phone_or_id' => '01711111111',
            'controllers' => 3,
        ])->assertCreated();

        $this->assertSame('250.00', $response->json('hourly_rate'));
        $this->assertSame(3, $response->json('controllers'));
    }

    public function test_one_controller_pays_only_the_base_rate(): void
    {
        $response = $this->apiPost($this->admin, "/api/checkin/{$this->station->id}", [
            'name' => 'Rafi Ahmed',
            'phone_or_id' => '01711111111',
            'controllers' => 1,
        ])->assertCreated();

        $this->assertSame('150.00', $response->json('hourly_rate'));
    }

    public function test_the_base_and_extra_rates_are_snapshotted_separately(): void
    {
        $response = $this->apiPost($this->admin, "/api/checkin/{$this->station->id}", [
            'name' => 'Rafi Ahmed',
            'phone_or_id' => '01711111111',
            'controllers' => 2,
        ])->assertCreated();

        $this->assertSame('150.00', $response->json('base_rate'));
        $this->assertSame('50.00', $response->json('extra_controller_rate'));
        $this->assertSame('200.00', $response->json('hourly_rate'));
    }

    public function test_more_controllers_than_the_station_takes_is_rejected_naming_the_limit(): void
    {
        $response = $this->apiPost($this->admin, "/api/checkin/{$this->station->id}", [
            'name' => 'Rafi Ahmed',
            'phone_or_id' => '01711111111',
            'controllers' => 5,
        ]);

        $response->assertStatus(400);
        $this->assertStringContainsString('4', $response->json('message'));
    }

    /* ------------------------------------------------------ rate stability */

    public function test_changing_a_stations_rate_mid_session_does_not_change_that_sessions_bill(): void
    {
        $this->setSettings(['billing_round_minutes' => 1, 'round_amount_to' => 1]);

        $session = $this->makeSession($this->cafe, $this->station, $this->customer, 60);

        // The owner puts prices up while the session is still running.
        $this->apiPatch($this->admin, "/api/stations/{$this->station->id}", [
            'hourly_rate' => '500.00',
        ])->assertOk();

        $invoice = $this->apiPost($this->admin, "/api/checkout/{$session->id}")
            ->assertCreated()
            ->json();

        // Billed at the rate agreed when the player sat down, not the new one.
        $this->assertSame('150.00', $invoice['hourly_rate']);
        $this->assertSame('150.00', $invoice['session_amount']);
    }

    public function test_an_invoice_already_issued_is_untouched_by_a_later_price_change(): void
    {
        $this->setSettings(['billing_round_minutes' => 1, 'round_amount_to' => 1]);

        $invoice = $this->billFor(60);
        $this->assertSame('150.00', $invoice['session_amount']);

        $this->apiPatch($this->admin, "/api/stations/{$this->station->id}", [
            'hourly_rate' => '999.00',
        ])->assertOk();

        $again = $this->apiGet($this->admin, "/api/sessions?limit=1")->json();
        $this->assertNotEmpty($again);

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice['id'],
            'session_amount' => '150.00',
        ]);
    }

    /* ----------------------------------------------------------- live cost */

    public function test_the_live_cost_is_unrounded(): void
    {
        // 22 minutes at 150/hr is 55.00 exactly. With a 15-minute block and a
        // 5-taka step a FINISHED session would bill 30 min -> 75. The live
        // figure must show neither rounding.
        $this->setSettings(['billing_round_minutes' => 15, 'round_amount_to' => 5]);

        $this->makeSession($this->cafe, $this->station, $this->customer, 22);

        $row = collect($this->apiGet($this->admin, '/api/sessions/active')->json())
            ->firstWhere('station_id', $this->station->id);

        $this->assertSame(22, $row['elapsed_minutes']);
        $this->assertSame('55.00', $row['running_cost']);
    }

    public function test_the_live_cost_keeps_odd_minutes_exact(): void
    {
        $this->setSettings(['billing_round_minutes' => 15, 'round_amount_to' => 5]);

        // 7 min at 150/hr = 17.50
        $this->makeSession($this->cafe, $this->station, $this->customer, 7);

        $row = collect($this->apiGet($this->admin, '/api/sessions/active')->json())
            ->firstWhere('station_id', $this->station->id);

        $this->assertSame('17.50', $row['running_cost']);
    }

    public function test_a_free_station_reports_no_running_cost(): void
    {
        $row = collect($this->apiGet($this->admin, '/api/sessions/active')->json())
            ->firstWhere('station_id', $this->station->id);

        $this->assertSame('free', $row['status']);
        $this->assertNull($row['running_cost']);
        $this->assertNull($row['session_id']);
    }

    /* ------------------------------------------------------ money as strings */

    public function test_money_is_serialised_as_strings_not_floats(): void
    {
        $invoice = $this->billFor(60);

        foreach (['total_amount', 'session_amount', 'items_amount', 'discount_amount'] as $field) {
            $this->assertIsString($invoice[$field], "{$field} must be a string");
            $this->assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', $invoice[$field]);
        }
    }

    public function test_the_invoice_reconciles_on_creation(): void
    {
        $invoice = $this->billFor(60);

        // total = session + items - discount
        $expected = Money::of($invoice['session_amount'])
            ->plus(Money::of($invoice['items_amount']))
            ->minus(Money::of($invoice['discount_amount']));

        $this->assertSame(Money::str($expected), $invoice['total_amount']);
    }

    /* ------------------------------------------------ per-cafe billing rules */

    public function test_two_cafes_can_hold_different_billing_rules(): void
    {
        $other = $this->makeCafe('Other Cafe');
        $otherAdmin = $this->makeUser($other, 'admin');
        $otherStation = $this->makeStation($other);
        $otherCustomer = $this->makeCustomer($other);

        $this->setSettings(['billing_round_minutes' => 15, 'round_amount_to' => 1]);
        app(SettingsService::class)->put($other->id, ['billing_round_minutes' => 60, 'round_amount_to' => 1]);

        $mine = $this->billFor(20);

        $theirSession = $this->makeSession($other, $otherStation, $otherCustomer, 20);
        $theirs = $this->apiPost($otherAdmin, "/api/checkout/{$theirSession->id}")->assertCreated()->json();

        $this->assertSame(30, $mine['duration_minutes']);
        $this->assertSame(60, $theirs['duration_minutes']);
    }

    public function test_checkout_marks_the_session_completed_and_frees_the_station(): void
    {
        $session = $this->makeSession($this->cafe, $this->station, $this->customer, 30);

        $this->apiPost($this->admin, "/api/checkout/{$session->id}")->assertCreated();

        $this->assertDatabaseHas('sessions', ['id' => $session->id, 'status' => 'completed']);

        $row = collect($this->apiGet($this->admin, '/api/sessions/active')->json())
            ->firstWhere('station_id', $this->station->id);

        $this->assertSame('free', $row['status']);
    }

    public function test_a_session_cannot_be_checked_out_twice(): void
    {
        $session = $this->makeSession($this->cafe, $this->station, $this->customer, 30);

        $this->apiPost($this->admin, "/api/checkout/{$session->id}")->assertCreated();
        $this->apiPost($this->admin, "/api/checkout/{$session->id}")->assertStatus(409);
    }

    public function test_a_station_takes_only_one_session_at_a_time(): void
    {
        $this->makeSession($this->cafe, $this->station, $this->customer, 10);

        $this->apiPost($this->admin, "/api/checkin/{$this->station->id}", [
            'name' => 'Someone Else',
            'phone_or_id' => '01722222222',
        ])->assertStatus(409);
    }

    public function test_checkin_on_a_station_under_maintenance_is_refused(): void
    {
        $this->station->update(['maintenance' => true]);

        $this->apiPost($this->admin, "/api/checkin/{$this->station->id}", [
            'name' => 'Rafi Ahmed',
            'phone_or_id' => '01711111111',
        ])->assertStatus(400);
    }

    public function test_checkin_on_an_inactive_station_is_refused(): void
    {
        $this->station->update(['is_active' => false]);

        $this->apiPost($this->admin, "/api/checkin/{$this->station->id}", [
            'name' => 'Rafi Ahmed',
            'phone_or_id' => '01711111111',
        ])->assertStatus(400);
    }
}
