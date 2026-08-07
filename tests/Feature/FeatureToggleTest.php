<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Cafe;
use Tests\TestCase;

/**
 * Optional modules the platform owner grants a cafe.
 *
 * The point these tests defend: hiding a sidebar item is presentation. If the
 * routes still answer, the cafe simply uses what it was not given.
 */
class FeatureToggleTest extends TestCase
{
    private Cafe $cafe;

    private AdminUser $admin;

    private AdminUser $staff;

    private AdminUser $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cafe = $this->makeCafe();
        $this->admin = $this->makeUser($this->cafe, 'admin', 'admin@example.com');
        $this->staff = $this->makeUser($this->cafe, 'staff', 'staff@example.com');
        $this->superadmin = $this->makeUser(null, 'superadmin', 'owner@example.com');
    }

    private function disable(string ...$features): void
    {
        $this->apiPatch(
            $this->superadmin,
            "/api/cafes/{$this->cafe->id}/features",
            array_fill_keys($features, false),
        )->assertOk();
    }

    /* ------------------------------------------------------------ defaults */

    public function test_every_module_is_on_by_default(): void
    {
        // An absent row means enabled, so cafes that already existed keep
        // everything they had.
        $this->apiGet($this->admin, '/api/features')->assertOk()->assertJson([
            'products' => true,
            'loyalty' => true,
            'bookings' => true,
        ]);
    }

    public function test_staff_can_read_the_feature_map(): void
    {
        // The sidebar needs it, and staff have a sidebar.
        $this->apiGet($this->staff, '/api/features')->assertOk();
    }

    /* --------------------------------------------------------- enforcement */

    public function test_disabling_products_refuses_the_product_routes(): void
    {
        $this->disable('products');

        $response = $this->apiGet($this->admin, '/api/products');

        $response->assertForbidden();
        $this->assertSame('products', $response->json('feature'));
        $this->assertStringContainsString('Products', $response->json('message'));
    }

    public function test_disabling_products_also_refuses_writing_them(): void
    {
        $this->disable('products');

        $this->apiPost($this->admin, '/api/products', ['name' => 'Sneaky', 'price' => '10'])
            ->assertForbidden();

        $this->assertDatabaseMissing('products', ['name' => 'Sneaky']);
    }

    public function test_disabling_loyalty_refuses_packages_and_tiers(): void
    {
        $this->disable('loyalty');

        $this->apiGet($this->admin, '/api/packages')->assertForbidden();
        $this->apiGet($this->admin, '/api/tiers')->assertForbidden();
        $this->apiPost($this->admin, '/api/tiers', ['name' => 'Gold'])->assertForbidden();
    }

    public function test_disabling_bookings_refuses_the_booking_routes(): void
    {
        $station = $this->makeStation($this->cafe);

        $this->disable('bookings');

        $this->apiGet($this->admin, '/api/bookings')->assertForbidden();

        $this->apiPost($this->admin, '/api/bookings', [
            'station_id' => $station->id,
            'customer_name' => 'Rafi Ahmed',
            'customer_phone' => '01711111111',
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->addHours(2)->format('Y-m-d H:i:s'),
        ])->assertForbidden();

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_disabling_a_module_leaves_the_core_alone(): void
    {
        $this->disable('products', 'loyalty', 'bookings');

        // Renting screens is the product. None of this is optional.
        $this->apiGet($this->admin, '/api/stations')->assertOk();
        $this->apiGet($this->admin, '/api/sessions/active')->assertOk();
        $this->apiGet($this->admin, '/api/invoices')->assertOk();
        $this->apiGet($this->admin, '/api/customers')->assertOk();
        $this->apiGet($this->admin, '/api/shifts')->assertOk();
        $this->apiGet($this->admin, '/api/analytics/profit')->assertOk();
        $this->apiGet($this->admin, '/api/settings')->assertOk();
    }

    public function test_a_module_can_be_turned_back_on(): void
    {
        $this->disable('products');
        $this->apiGet($this->admin, '/api/products')->assertForbidden();

        $this->apiPatch($this->superadmin, "/api/cafes/{$this->cafe->id}/features", ['products' => true])
            ->assertOk();

        $this->apiGet($this->admin, '/api/products')->assertOk();
    }

    /* --------------------------------------------------- who may grant them */

    public function test_a_cafe_admin_cannot_grant_itself_a_module(): void
    {
        $this->disable('products');

        // The whole point: otherwise an admin just switches on what they were
        // not given.
        $this->apiPatch($this->admin, "/api/cafes/{$this->cafe->id}/features", ['products' => true])
            ->assertForbidden();

        $this->apiGet($this->admin, '/api/products')->assertForbidden();
    }

    public function test_staff_cannot_grant_a_module_either(): void
    {
        $this->apiPatch($this->staff, "/api/cafes/{$this->cafe->id}/features", ['products' => false])
            ->assertForbidden();
    }

    public function test_granting_on_an_unknown_cafe_is_404(): void
    {
        $this->apiPatch($this->superadmin, '/api/cafes/99999/features', ['products' => false])
            ->assertNotFound();
    }

    public function test_an_unknown_feature_name_is_ignored_rather_than_stored(): void
    {
        $this->apiPatch($this->superadmin, "/api/cafes/{$this->cafe->id}/features", [
            'products' => false,
            'time_travel' => false,
        ])->assertOk();

        $this->assertDatabaseMissing('cafe_features', ['feature' => 'time_travel']);
        $this->assertDatabaseHas('cafe_features', ['feature' => 'products', 'enabled' => false]);
    }

    /* -------------------------------------------------------------- scoping */

    public function test_features_are_per_cafe(): void
    {
        $other = $this->makeCafe('Other Cafe');
        $otherAdmin = $this->makeUser($other, 'admin', 'other@example.com');

        $this->disable('products');

        $this->apiGet($this->admin, '/api/products')->assertForbidden();
        // The neighbour is untouched.
        $this->apiGet($otherAdmin, '/api/products')->assertOk();
    }

    public function test_the_owner_sees_each_cafes_switches_on_its_card(): void
    {
        $this->disable('bookings');

        $card = collect($this->apiGet($this->superadmin, '/api/cafes/mine')->json())
            ->firstWhere('id', $this->cafe->id);

        $bookings = collect($card['features'])->firstWhere('key', 'bookings');
        $products = collect($card['features'])->firstWhere('key', 'products');

        $this->assertFalse($bookings['enabled']);
        $this->assertTrue($products['enabled']);
        $this->assertSame('Bookings', $bookings['label']);
    }

    public function test_a_cafe_admin_is_not_told_about_other_cafes_features(): void
    {
        $card = $this->apiGet($this->admin, '/api/cafes/mine')->assertOk()->json();

        $this->assertCount(1, $card);
        // The switches belong to the owner's screen, not a tenant's.
        $this->assertNull($card[0]['features']);
    }

    public function test_a_grant_is_written_to_the_cafes_activity_log(): void
    {
        $this->disable('products');

        $event = collect($this->apiGet($this->admin, '/api/audit')->json())
            ->firstWhere('action', 'cafe_features');

        $this->assertNotNull($event);
        $this->assertSame('owner@example.com', $event['actor_email']);
        $this->assertStringContainsString('products off', $event['summary']);
    }
}
