<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Cafe;
use Tests\TestCase;

/** §10 — superadmin onboarding, staff accounts and per-cafe settings. */
class CafeManagementTest extends TestCase
{
    private Cafe $cafe;

    private AdminUser $admin;

    private AdminUser $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cafe = $this->makeCafe('My Gaming Cafe');
        $this->admin = $this->makeUser($this->cafe, 'admin', 'admin@example.com');
        $this->superadmin = $this->makeUser(null, 'superadmin', 'owner@example.com');
    }

    public function test_a_superadmin_onboards_a_cafe_with_its_first_admin(): void
    {
        $cafe = $this->apiPost($this->superadmin, '/api/cafes', [
            'name' => 'Dhaka Esports Lounge',
            'admin_email' => 'owner@dhaka.test',
            'admin_password' => 'secret123',
        ])->assertCreated()->json();

        $this->assertSame('Dhaka Esports Lounge', $cafe['name']);
        $this->assertSame('dhaka-esports-lounge', $cafe['slug']);
        $this->assertTrue($cafe['is_active']);

        // The new admin can sign straight in.
        $this->postJson('/api/auth/login', [
            'email' => 'owner@dhaka.test', 'password' => 'secret123',
        ])->assertOk()->assertJson(['role' => 'admin', 'cafe_id' => $cafe['id']]);
    }

    public function test_cafe_slugs_do_not_collide(): void
    {
        foreach (['Game Zone', 'Game Zone', 'Game Zone'] as $name) {
            $this->apiPost($this->superadmin, '/api/cafes', [
                'name' => $name,
                'admin_email' => 'a'.mt_rand(1, 999999).'@example.com',
                'admin_password' => 'secret123',
            ])->assertCreated();
        }

        $slugs = Cafe::where('name', 'Game Zone')->pluck('slug')->all();

        $this->assertSame(['game-zone', 'game-zone-2', 'game-zone-3'], $slugs);
    }

    public function test_an_admin_email_must_be_unique_platform_wide(): void
    {
        $this->apiPost($this->superadmin, '/api/cafes', [
            'name' => 'Second Cafe',
            'admin_email' => 'admin@example.com', // already taken in another cafe
            'admin_password' => 'secret123',
        ])->assertStatus(422);
    }

    public function test_a_superadmin_can_suspend_and_restore_a_cafe(): void
    {
        $this->apiPatch($this->superadmin, "/api/cafes/{$this->cafe->id}", ['is_active' => false])
            ->assertOk()->assertJson(['is_active' => false]);

        $this->postJson('/api/auth/login', [
            'email' => 'admin@example.com', 'password' => 'secret123',
        ])->assertForbidden();

        $this->apiPatch($this->superadmin, "/api/cafes/{$this->cafe->id}", ['is_active' => true])
            ->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => 'admin@example.com', 'password' => 'secret123',
        ])->assertOk();
    }

    public function test_a_superadmin_can_rename_a_cafe(): void
    {
        $this->apiPatch($this->superadmin, "/api/cafes/{$this->cafe->id}", ['name' => 'Renamed Cafe'])
            ->assertOk()->assertJson(['name' => 'Renamed Cafe']);
    }

    public function test_the_slug_follows_a_rename(): void
    {
        // It is printed under the name on every cafe card, so a slug left
        // behind reads as a stale record.
        $this->apiPatch($this->superadmin, "/api/cafes/{$this->cafe->id}", ['name' => 'Neon Arena'])
            ->assertOk()->assertJson(['slug' => 'neon-arena']);
    }

    public function test_renaming_a_cafe_to_its_own_name_does_not_walk_the_slug(): void
    {
        // The uniqueness check has to ignore the row being saved, or a save
        // with the name unchanged collides with itself and lands on -2.
        $this->apiPatch($this->superadmin, "/api/cafes/{$this->cafe->id}", [
            'name' => 'My Gaming Cafe',
            'contact_email' => 'hello@example.com',
        ])->assertOk()->assertJson(['slug' => 'my-gaming-cafe', 'contact_email' => 'hello@example.com']);
    }

    public function test_a_rename_still_cannot_take_another_cafes_slug(): void
    {
        $other = $this->makeCafe('Neon Arena');

        $this->apiPatch($this->superadmin, "/api/cafes/{$this->cafe->id}", ['name' => 'Neon Arena'])
            ->assertOk()->assertJson(['slug' => 'neon-arena-2']);

        $this->assertSame('neon-arena', $other->fresh()->slug);
    }

    public function test_a_superadmin_can_change_a_cafes_contact_email(): void
    {
        $this->apiPatch($this->superadmin, "/api/cafes/{$this->cafe->id}", ['contact_email' => 'new@example.com'])
            ->assertOk()->assertJson(['contact_email' => 'new@example.com']);

        $this->apiPatch($this->superadmin, "/api/cafes/{$this->cafe->id}", ['contact_email' => null])
            ->assertOk()->assertJson(['contact_email' => null]);
    }

    /* ------------------------- editing a cafe's accounts from the outside */

    public function test_a_superadmin_can_manage_another_cafes_accounts_without_switching_into_it(): void
    {
        // This is what the Edit dialog on the Cafes screen does: it names the
        // cafe on the request rather than switching the whole app into it.
        $accounts = $this->apiGet($this->superadmin, '/api/staff', $this->cafe)->assertOk()->json();
        $this->assertCount(1, $accounts);
        $this->assertSame('admin@example.com', $accounts[0]['email']);

        $this->apiPatch(
            $this->superadmin,
            "/api/staff/{$this->admin->id}",
            ['email' => 'newadmin@example.com'],
            $this->cafe,
        )->assertOk()->assertJson(['email' => 'newadmin@example.com']);

        $this->assertSame('newadmin@example.com', $this->admin->fresh()->email);
    }

    public function test_changing_an_admins_email_signs_them_out(): void
    {
        $token = $this->tokenFor($this->admin);

        $this->apiPatch(
            $this->superadmin,
            "/api/staff/{$this->admin->id}",
            ['password' => 'brand-new-one'],
            $this->cafe,
        )->assertOk();

        $this->getJson('/api/stations', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    }

    public function test_the_owner_cannot_leave_a_cafe_without_an_admin(): void
    {
        // The cafe would then be one only the platform owner could fix.
        $this->apiDelete($this->superadmin, "/api/staff/{$this->admin->id}", $this->cafe)
            ->assertStatus(400);

        $this->apiPatch(
            $this->superadmin,
            "/api/staff/{$this->admin->id}",
            ['role' => 'staff'],
            $this->cafe,
        )->assertStatus(400);
    }

    public function test_a_superadmin_can_add_an_admin_to_another_cafe(): void
    {
        $this->apiPost($this->superadmin, '/api/staff', [
            'email' => 'second@example.com',
            'password' => 'secret123',
            'role' => 'admin',
        ], $this->cafe)->assertCreated();

        $this->assertSame(
            $this->cafe->id,
            AdminUser::where('email', 'second@example.com')->value('cafe_id'),
        );
    }

    public function test_an_admin_of_one_cafe_cannot_edit_another_cafes_accounts(): void
    {
        $other = $this->makeCafe('Other Cafe');
        $theirs = $this->makeUser($other, 'admin', 'other@example.com');

        // X-Cafe-Id is ignored for a cafe-bound account, so this resolves
        // inside their OWN cafe, where that id does not exist.
        $this->apiPatch($this->admin, "/api/staff/{$theirs->id}", ['email' => 'stolen@example.com'], $other)
            ->assertNotFound();

        $this->assertSame('other@example.com', $theirs->fresh()->email);
    }

    public function test_an_admin_cannot_create_or_suspend_a_cafe(): void
    {
        $this->apiPost($this->admin, '/api/cafes', [
            'name' => 'Mine Now', 'admin_email' => 'x@example.com', 'admin_password' => 'secret123',
        ])->assertForbidden();

        $this->apiPatch($this->admin, "/api/cafes/{$this->cafe->id}", ['is_active' => false])
            ->assertForbidden();
    }

    public function test_an_admin_sees_only_their_own_cafe_but_the_owner_sees_all(): void
    {
        $this->makeCafe('Another Cafe');

        $mine = $this->apiGet($this->admin, '/api/cafes/mine')->assertOk()->json();
        $this->assertCount(1, $mine);
        $this->assertSame('My Gaming Cafe', $mine[0]['name']);

        // The platform owner sees every cafe, with the counts the cards show.
        $all = $this->apiGet($this->superadmin, '/api/cafes/mine')->assertOk()->json();
        $this->assertCount(2, $all);
        $this->assertSame(1, collect($all)->firstWhere('name', 'My Gaming Cafe')['account_count']);
    }

    public function test_an_admin_creates_a_staff_account_inside_their_own_cafe(): void
    {
        $created = $this->apiPost($this->admin, '/api/staff', [
            'email' => 'newstaff@example.com', 'password' => 'secret123', 'role' => 'staff',
        ])->assertCreated()->json();

        $this->assertSame($this->cafe->id, $created['cafe_id']);

        $this->postJson('/api/auth/login', [
            'email' => 'newstaff@example.com', 'password' => 'secret123',
        ])->assertOk()->assertJson(['role' => 'staff']);
    }

    public function test_a_staff_password_must_be_at_least_six_characters(): void
    {
        $this->apiPost($this->admin, '/api/staff', [
            'email' => 'short@example.com', 'password' => 'abc', 'role' => 'staff',
        ])->assertStatus(422);
    }

    public function test_a_staff_role_outside_admin_or_staff_is_rejected(): void
    {
        // Nobody mints a superadmin through a tenant's own staff screen.
        $this->apiPost($this->admin, '/api/staff', [
            'email' => 'sneaky@example.com', 'password' => 'secret123', 'role' => 'superadmin',
        ])->assertStatus(422);
    }

    public function test_settings_validation_rejects_out_of_range_values(): void
    {
        $this->apiPatch($this->admin, '/api/settings', ['billing_round_minutes' => 0])->assertStatus(422);
        $this->apiPatch($this->admin, '/api/settings', ['billing_round_minutes' => 241])->assertStatus(422);
        $this->apiPatch($this->admin, '/api/settings', ['round_amount_to' => 0])->assertStatus(422);
        $this->apiPatch($this->admin, '/api/settings', ['round_amount_to' => 1001])->assertStatus(422);
        $this->apiPatch($this->admin, '/api/settings', ['open_hour' => 24])->assertStatus(422);
        $this->apiPatch($this->admin, '/api/settings', ['close_hour' => 25])->assertStatus(422);
    }

    public function test_settings_default_to_the_documented_values(): void
    {
        $this->apiGet($this->admin, '/api/settings')->assertOk()->assertJson([
            'billing_round_minutes' => 15,
            'round_amount_to' => 5,
            'open_hour' => 10,
            'close_hour' => 23,
        ]);
    }

    public function test_a_station_with_history_is_deactivated_rather_than_deleted(): void
    {
        $station = $this->makeStation($this->cafe);
        $customer = $this->makeCustomer($this->cafe);
        $this->makeSession($this->cafe, $station, $customer, 30);

        $this->apiDelete($this->admin, "/api/stations/{$station->id}")->assertNoContent();

        // Its past sessions and invoices still need something to point at.
        $this->assertDatabaseHas('stations', ['id' => $station->id, 'is_active' => false]);
    }

    public function test_a_station_with_no_history_is_deleted_outright(): void
    {
        $station = $this->makeStation($this->cafe);

        $this->apiDelete($this->admin, "/api/stations/{$station->id}")->assertNoContent();

        $this->assertDatabaseMissing('stations', ['id' => $station->id]);
    }

    public function test_station_validation_rejects_a_free_or_overloaded_station(): void
    {
        $this->apiPost($this->admin, '/api/stations', [
            'name' => 'Free Play', 'type' => 'PS5', 'hourly_rate' => '0',
        ])->assertStatus(422);

        $this->apiPost($this->admin, '/api/stations', [
            'name' => 'Too Many', 'type' => 'PS5', 'hourly_rate' => '150', 'max_controllers' => 9,
        ])->assertStatus(422);
    }

    public function test_a_product_that_has_been_sold_is_retired_rather_than_deleted(): void
    {
        $station = $this->makeStation($this->cafe);
        $customer = $this->makeCustomer($this->cafe);
        $session = $this->makeSession($this->cafe, $station, $customer, 60);

        $invoice = $this->apiPost($this->admin, "/api/checkout/{$session->id}")->assertCreated()->json();

        $product = $this->apiPost($this->admin, '/api/products', [
            'name' => 'Nachos', 'price' => '150', 'cost_price' => '88',
        ])->assertCreated()->json();

        $this->apiPost($this->admin, "/api/invoices/{$invoice['id']}/items", [
            'product_id' => $product['id'], 'quantity' => 1,
        ])->assertCreated();

        $this->apiDelete($this->admin, "/api/products/{$product['id']}")->assertNoContent();

        $this->assertDatabaseHas('products', ['id' => $product['id'], 'is_active' => false]);
    }

    public function test_the_health_endpoint_needs_no_account(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJson(['status' => 'ok']);
    }

    public function test_a_package_must_cost_and_grant_something(): void
    {
        $this->apiPost($this->admin, '/api/packages', ['name' => 'Free', 'price' => '0', 'credit' => '100'])
            ->assertStatus(422);

        $this->apiPost($this->admin, '/api/packages', ['name' => 'Empty', 'price' => '100', 'credit' => '0'])
            ->assertStatus(422);
    }

    public function test_active_only_filters_the_catalogue(): void
    {
        $live = $this->apiPost($this->admin, '/api/products', ['name' => 'Live', 'price' => '10'])
            ->assertCreated()->json();

        $retired = $this->apiPost($this->admin, '/api/products', ['name' => 'Retired', 'price' => '10'])
            ->assertCreated()->json();

        $this->apiPatch($this->admin, "/api/products/{$retired['id']}", ['is_active' => false])->assertOk();

        $all = $this->apiGet($this->admin, '/api/products')->json();
        $active = $this->apiGet($this->admin, '/api/products?active_only=1')->json();

        $this->assertCount(2, $all);
        $this->assertCount(1, $active);
        $this->assertSame($live['id'], $active[0]['id']);
    }
}
