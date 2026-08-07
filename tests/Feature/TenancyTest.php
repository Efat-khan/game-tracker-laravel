<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Booking;
use App\Models\Cafe;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Station;
use App\Services\SettingsService;
use Tests\TestCase;

/**
 * §4 and §10. This suite actively tries to break out of a tenant.
 *
 * The rule under test throughout: another cafe's record must answer 404, never
 * 403 — a 403 confirms the record exists, which is itself a leak.
 */
class TenancyTest extends TestCase
{
    private Cafe $alpha;

    private Cafe $beta;

    private AdminUser $alphaAdmin;

    private AdminUser $betaAdmin;

    private AdminUser $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->makeCafe('Alpha Cafe');
        $this->beta = $this->makeCafe('Beta Cafe');
        $this->alphaAdmin = $this->makeUser($this->alpha, 'admin', 'alpha@example.com');
        $this->betaAdmin = $this->makeUser($this->beta, 'admin', 'beta@example.com');
        $this->superadmin = $this->makeUser(null, 'superadmin', 'owner@example.com');
    }

    /* ------------------------------------------------------- list isolation */

    public function test_station_lists_are_isolated(): void
    {
        $this->makeStation($this->alpha, ['name' => 'Alpha PS5']);
        $this->makeStation($this->beta, ['name' => 'Beta PS5']);

        $names = collect($this->apiGet($this->alphaAdmin, '/api/stations')->json())->pluck('name');

        $this->assertEquals(['Alpha PS5'], $names->all());
    }

    public function test_customer_lists_are_isolated(): void
    {
        $this->makeCustomer($this->alpha, ['name' => 'Alpha Customer']);
        $this->makeCustomer($this->beta, ['name' => 'Beta Customer']);

        $names = collect($this->apiGet($this->betaAdmin, '/api/customers')->json())->pluck('name');

        $this->assertEquals(['Beta Customer'], $names->all());
    }

    public function test_invoice_lists_are_isolated(): void
    {
        $this->completedInvoice($this->alpha, $this->alphaAdmin);
        $this->completedInvoice($this->beta, $this->betaAdmin);

        $invoices = $this->apiGet($this->alphaAdmin, '/api/invoices')->json();

        $this->assertCount(1, $invoices);
        $this->assertSame('Alpha Cafe', $this->alpha->name);
    }

    public function test_product_lists_are_isolated(): void
    {
        $this->apiPost($this->alphaAdmin, '/api/products', ['name' => 'Alpha Chips', 'price' => '35']);
        $this->apiPost($this->betaAdmin, '/api/products', ['name' => 'Beta Chips', 'price' => '35']);

        $names = collect($this->apiGet($this->alphaAdmin, '/api/products')->json())->pluck('name');

        $this->assertEquals(['Alpha Chips'], $names->all());
    }

    public function test_shift_lists_are_isolated(): void
    {
        $this->apiPost($this->alphaAdmin, '/api/shifts/open', ['opening_float' => '1000']);
        $this->apiPost($this->betaAdmin, '/api/shifts/open', ['opening_float' => '2000']);

        $shifts = $this->apiGet($this->alphaAdmin, '/api/shifts')->json();

        $this->assertCount(1, $shifts);
        $this->assertSame('1000.00', $shifts[0]['opening_float']);
    }

    public function test_audit_logs_are_isolated(): void
    {
        $this->makeStation($this->alpha);
        $this->apiPost($this->alphaAdmin, '/api/stations', ['name' => 'A', 'type' => 'PS5', 'hourly_rate' => '100']);
        $this->apiPost($this->betaAdmin, '/api/stations', ['name' => 'B', 'type' => 'PS5', 'hourly_rate' => '100']);

        $events = $this->apiGet($this->betaAdmin, '/api/audit')->json();

        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $this->assertStringNotContainsString('alpha@example.com', $event['actor_email']);
        }
    }

    /* ------------------------------------------- by-id lookups answer 404 */

    public function test_reading_another_cafes_station_by_id_is_404_not_403(): void
    {
        $station = $this->makeStation($this->beta);

        $this->apiPatch($this->alphaAdmin, "/api/stations/{$station->id}", ['name' => 'Stolen'])
            ->assertNotFound();
    }

    public function test_patching_another_cafes_station_is_404(): void
    {
        $station = $this->makeStation($this->beta);

        $this->apiPatch($this->alphaAdmin, "/api/stations/{$station->id}", ['hourly_rate' => '1'])
            ->assertNotFound();

        $this->assertDatabaseHas('stations', ['id' => $station->id, 'hourly_rate' => '150.00']);
    }

    public function test_deleting_another_cafes_station_is_404(): void
    {
        $station = $this->makeStation($this->beta);

        $this->apiDelete($this->alphaAdmin, "/api/stations/{$station->id}")->assertNotFound();

        $this->assertDatabaseHas('stations', ['id' => $station->id]);
    }

    public function test_reading_another_cafes_customer_is_404(): void
    {
        $customer = $this->makeCustomer($this->beta);

        $this->apiGet($this->alphaAdmin, "/api/customers/{$customer->id}")->assertNotFound();
    }

    public function test_reading_another_cafes_wallet_is_404(): void
    {
        $customer = $this->makeCustomer($this->beta);

        $this->apiGet($this->alphaAdmin, "/api/customers/{$customer->id}/wallet")->assertNotFound();
    }

    public function test_topping_up_another_cafes_customer_is_404(): void
    {
        $customer = $this->makeCustomer($this->beta);

        $this->apiPost($this->alphaAdmin, "/api/customers/{$customer->id}/topup", ['amount' => '500'])
            ->assertNotFound();

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'balance' => '0.00']);
    }

    public function test_checking_out_another_cafes_session_is_404(): void
    {
        $station = $this->makeStation($this->beta);
        $customer = $this->makeCustomer($this->beta);
        $session = $this->makeSession($this->beta, $station, $customer, 60);

        $this->apiPost($this->alphaAdmin, "/api/checkout/{$session->id}")->assertNotFound();

        $this->assertDatabaseHas('sessions', ['id' => $session->id, 'status' => 'active']);
    }

    public function test_voiding_another_cafes_invoice_is_404(): void
    {
        $invoice = $this->completedInvoice($this->beta, $this->betaAdmin);

        $this->apiPost($this->alphaAdmin, "/api/invoices/{$invoice['id']}/void", ['reason' => 'not mine'])
            ->assertNotFound();

        $this->assertDatabaseHas('invoices', ['id' => $invoice['id'], 'status' => 'active']);
    }

    public function test_reading_another_cafes_shift_is_404(): void
    {
        $shift = $this->apiPost($this->betaAdmin, '/api/shifts/open', ['opening_float' => '500'])->json();

        $this->apiGet($this->alphaAdmin, "/api/shifts/{$shift['id']}")->assertNotFound();
    }

    public function test_deleting_another_cafes_product_is_404(): void
    {
        $product = Product::create([
            'cafe_id' => $this->beta->id, 'name' => 'Beta Chips', 'category' => 'Snacks',
            'price' => '35.00', 'cost_price' => '20.00', 'created_at' => now(),
        ]);

        $this->apiDelete($this->alphaAdmin, "/api/products/{$product->id}")->assertNotFound();

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    /* -------------------------------------------------- shared identifiers */

    public function test_the_same_phone_in_two_cafes_is_two_separate_customers(): void
    {
        $alphaStation = $this->makeStation($this->alpha);
        $betaStation = $this->makeStation($this->beta);

        $this->apiPost($this->alphaAdmin, "/api/checkin/{$alphaStation->id}", [
            'name' => 'Rafi at Alpha', 'phone_or_id' => '01799999999',
        ])->assertCreated();

        $this->apiPost($this->betaAdmin, "/api/checkin/{$betaStation->id}", [
            'name' => 'Rafi at Beta', 'phone_or_id' => '01799999999',
        ])->assertCreated();

        $customers = Customer::where('phone_or_id', '01799999999')->get();

        $this->assertCount(2, $customers);
        $this->assertEqualsCanonicalizing(
            [$this->alpha->id, $this->beta->id],
            $customers->pluck('cafe_id')->all(),
        );
    }

    public function test_two_cafes_can_book_the_same_slot(): void
    {
        $alphaStation = $this->makeStation($this->alpha);
        $betaStation = $this->makeStation($this->beta);

        $slot = [
            'starts_at' => now()->addDay()->setTime(18, 0)->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->setTime(20, 0)->format('Y-m-d H:i:s'),
            'customer_name' => 'Rafi Ahmed',
            'customer_phone' => '01700000000',
        ];

        $this->apiPost($this->alphaAdmin, '/api/bookings', $slot + ['station_id' => $alphaStation->id])
            ->assertCreated();

        $this->apiPost($this->betaAdmin, '/api/bookings', $slot + ['station_id' => $betaStation->id])
            ->assertCreated();

        $this->assertSame(2, Booking::count());
    }

    public function test_settings_are_per_cafe(): void
    {
        $this->apiPatch($this->alphaAdmin, '/api/settings', ['billing_round_minutes' => 30])->assertOk();

        $this->assertSame(30, $this->apiGet($this->alphaAdmin, '/api/settings')->json('billing_round_minutes'));
        // Beta keeps the default; one cafe's change never touches another.
        $this->assertSame(15, $this->apiGet($this->betaAdmin, '/api/settings')->json('billing_round_minutes'));
    }

    /* -------------------------------------------------------- superadmin */

    public function test_a_superadmin_without_a_cafe_header_gets_400(): void
    {
        $this->getJson('/api/stations', [
            'Authorization' => 'Bearer '.$this->tokenFor($this->superadmin),
        ])->assertStatus(400);
    }

    public function test_a_superadmin_with_a_bogus_cafe_id_gets_404(): void
    {
        $this->getJson('/api/stations', [
            'Authorization' => 'Bearer '.$this->tokenFor($this->superadmin),
            'X-Cafe-Id' => '99999',
        ])->assertNotFound();
    }

    public function test_a_superadmin_with_a_cafe_header_works_inside_that_cafe(): void
    {
        $this->makeStation($this->beta, ['name' => 'Beta PS5']);

        $names = collect($this->apiGet($this->superadmin, '/api/stations', $this->beta)->json())->pluck('name');

        $this->assertEquals(['Beta PS5'], $names->all());
    }

    public function test_a_superadmin_passes_admin_checks_once_inside_a_cafe(): void
    {
        // Otherwise the platform owner could open a tenant but not fix it (§3).
        $this->apiPost($this->superadmin, '/api/stations', [
            'name' => 'Fixed by owner', 'type' => 'PS5', 'hourly_rate' => '150',
        ], $this->beta)->assertCreated();

        $this->assertDatabaseHas('stations', ['name' => 'Fixed by owner', 'cafe_id' => $this->beta->id]);
    }

    public function test_a_superadmin_never_appears_in_any_cafes_staff_list(): void
    {
        foreach ([$this->alpha, $this->beta] as $cafe) {
            $emails = collect($this->apiGet($this->superadmin, '/api/staff', $cafe)->json())->pluck('email');

            $this->assertNotContains('owner@example.com', $emails->all());
        }

        // The bug this guards: an ORM default filing a null cafe_id into cafe 1.
        $this->assertNull(AdminUser::where('email', 'owner@example.com')->first()->cafe_id);
    }

    public function test_an_admin_token_ignores_a_cafe_header_it_sends_itself(): void
    {
        $this->makeStation($this->alpha, ['name' => 'Alpha PS5']);
        $this->makeStation($this->beta, ['name' => 'Beta PS5']);

        // Alpha's admin tries to widen their own reach by naming Beta.
        $names = collect($this->getJson('/api/stations', [
            'Authorization' => 'Bearer '.$this->tokenFor($this->alphaAdmin),
            'X-Cafe-Id' => (string) $this->beta->id,
        ])->json())->pluck('name');

        $this->assertEquals(['Alpha PS5'], $names->all());
    }

    public function test_a_suspended_cafe_cannot_log_in(): void
    {
        $suspended = $this->makeCafe('Suspended Cafe', active: false);
        $this->makeUser($suspended, 'admin', 'suspended@example.com');

        $this->postJson('/api/auth/login', [
            'email' => 'suspended@example.com',
            'password' => 'secret123',
        ])->assertForbidden();
    }

    public function test_analytics_never_mix_two_cafes_revenue(): void
    {
        $this->completedInvoice($this->alpha, $this->alphaAdmin);
        $this->completedInvoice($this->beta, $this->betaAdmin);
        $this->completedInvoice($this->beta, $this->betaAdmin);

        $alpha = $this->apiGet($this->alphaAdmin, '/api/analytics/profit')->json();
        $beta = $this->apiGet($this->betaAdmin, '/api/analytics/profit')->json();

        $this->assertSame('150.00', $alpha['total_revenue']);
        $this->assertSame('300.00', $beta['total_revenue']);
    }

    /** A completed 60-minute session at 150/hr, per-minute, no amount rounding. */
    private function completedInvoice(Cafe $cafe, AdminUser $admin): array
    {
        app(SettingsService::class)->put($cafe->id, ['billing_round_minutes' => 1, 'round_amount_to' => 1]);

        $station = Station::where('cafe_id', $cafe->id)->first() ?? $this->makeStation($cafe);
        $customer = $this->makeCustomer($cafe, ['phone_or_id' => '017'.mt_rand(10000000, 99999999)]);
        $session = $this->makeSession($cafe, $station, $customer, 60);

        return $this->apiPost($admin, "/api/checkout/{$session->id}")->assertCreated()->json();
    }
}
