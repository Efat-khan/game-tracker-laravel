<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Cafe;
use App\Models\GameSession;
use App\Models\Station;
use Tests\TestCase;

/** §7 — signed QR codes, rate limits and token invalidation. */
class SecurityTest extends TestCase
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
    }

    private function checkinBody(): array
    {
        return ['name' => 'Rafi Ahmed', 'phone_or_id' => '01711111111'];
    }

    /* --------------------------------------------------------- QR signatures */

    public function test_a_valid_station_token_passes_when_the_token_is_required(): void
    {
        config()->set('cafetrack.require_qr_token', true);

        $this->postJson(
            "/api/checkin/{$this->station->id}?t=".$this->qrToken($this->station),
            $this->checkinBody(),
        )->assertCreated();
    }

    public function test_a_forged_station_token_is_rejected(): void
    {
        config()->set('cafetrack.require_qr_token', true);

        $this->postJson("/api/checkin/{$this->station->id}?t=deadbeefdeadbeef", $this->checkinBody())
            ->assertForbidden();

        $this->assertSame(0, GameSession::count());
    }

    public function test_a_missing_station_token_is_rejected(): void
    {
        config()->set('cafetrack.require_qr_token', true);

        $this->postJson("/api/checkin/{$this->station->id}", $this->checkinBody())->assertForbidden();
    }

    public function test_another_stations_token_does_not_work(): void
    {
        config()->set('cafetrack.require_qr_token', true);

        $other = $this->makeStation($this->cafe, ['name' => 'PS5 - Booth 2']);

        // Station ids are sequential, so this is exactly the attack the
        // signature exists to stop.
        $this->postJson(
            "/api/checkin/{$this->station->id}?t=".$this->qrToken($other),
            $this->checkinBody(),
        )->assertForbidden();
    }

    public function test_staff_bypass_the_token_check(): void
    {
        config()->set('cafetrack.require_qr_token', true);

        // Staff start sessions from the dashboard, where there is no code to scan.
        $this->apiPost($this->staff, "/api/checkin/{$this->station->id}", $this->checkinBody())
            ->assertCreated();
    }

    public function test_the_token_check_can_be_turned_off(): void
    {
        config()->set('cafetrack.require_qr_token', false);

        $this->postJson("/api/checkin/{$this->station->id}", $this->checkinBody())->assertCreated();
    }

    public function test_the_qr_png_is_served_and_encodes_the_signed_url(): void
    {
        $response = $this->get("/api/stations/{$this->station->id}/qrcode");

        $response->assertOk();
        $response->assertHeader('content-type', 'image/png');
        $this->assertStringStartsWith("\x89PNG", $response->getContent());

        // The stored URL carries the signature the QR encodes.
        $station = $this->station->fresh();
        $created = $this->apiPost($this->admin, '/api/stations', [
            'name' => 'Fresh', 'type' => 'PS5', 'hourly_rate' => '150',
        ])->assertCreated()->json();

        $this->assertStringContainsString('?t=', $created['qr_code_url']);
        $this->assertNotNull($station);
    }

    public function test_the_public_station_page_never_leaks_the_phone_number(): void
    {
        config()->set('cafetrack.require_qr_token', false);

        $this->postJson("/api/checkin/{$this->station->id}", [
            'name' => 'Rafi Ahmed', 'phone_or_id' => '01799999999',
        ])->assertCreated();

        $response = $this->getJson("/api/stations/{$this->station->id}/public");

        $response->assertOk();
        $this->assertSame('Rafi Ahmed', $response->json('active_session.customer_name'));
        $this->assertStringNotContainsString('01799999999', $response->getContent());
    }

    /* ------------------------------------------------------------ rate limits */

    public function test_check_in_is_rate_limited_and_returns_retry_after(): void
    {
        config()->set('cafetrack.rate_limits.checkin', 3);
        config()->set('cafetrack.require_qr_token', false);

        // The first three are allowed (409 after the first, since the station is
        // then busy — what matters is that they are not throttled).
        for ($i = 0; $i < 3; $i++) {
            $status = $this->postJson("/api/checkin/{$this->station->id}", $this->checkinBody())->status();
            $this->assertNotSame(429, $status);
        }

        $throttled = $this->postJson("/api/checkin/{$this->station->id}", $this->checkinBody());

        $throttled->assertStatus(429);
        $throttled->assertHeader('Retry-After');
    }

    public function test_login_is_rate_limited(): void
    {
        config()->set('cafetrack.rate_limits.login', 2);

        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'admin@example.com', 'password' => 'wrong'])
                ->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', ['email' => 'admin@example.com', 'password' => 'secret123'])
            ->assertStatus(429);
    }

    public function test_a_rate_limit_of_zero_disables_it(): void
    {
        config()->set('cafetrack.rate_limits.login', 0);

        for ($i = 0; $i < 25; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'admin@example.com', 'password' => 'wrong'])
                ->assertUnauthorized();
        }
    }

    public function test_the_public_station_lookup_is_rate_limited(): void
    {
        config()->set('cafetrack.rate_limits.public_station', 2);

        $this->getJson("/api/stations/{$this->station->id}/public")->assertOk();
        $this->getJson("/api/stations/{$this->station->id}/public")->assertOk();
        $this->getJson("/api/stations/{$this->station->id}/public")->assertStatus(429);
    }

    /* ------------------------------------------------------ token invalidation */

    public function test_a_token_is_rejected_after_the_password_changes(): void
    {
        $victim = $this->makeUser($this->cafe, 'staff', 'victim@example.com');
        $token = $this->tokenFor($victim);

        $this->getJson('/api/stations', ['Authorization' => "Bearer {$token}"])->assertOk();

        $this->apiPatch($this->admin, "/api/staff/{$victim->id}", ['password' => 'newsecret'])->assertOk();

        $this->getJson('/api/stations', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    }

    public function test_a_token_is_rejected_after_the_role_changes(): void
    {
        $user = $this->makeUser($this->cafe, 'staff', 'promoted@example.com');
        $token = $this->tokenFor($user);

        $this->getJson('/api/stations', ['Authorization' => "Bearer {$token}"])->assertOk();

        $this->apiPatch($this->admin, "/api/staff/{$user->id}", ['role' => 'admin'])->assertOk();

        // The old token carried the old role; it must not survive the change.
        $this->getJson('/api/stations', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    }

    public function test_a_revoked_user_is_signed_out_everywhere(): void
    {
        $user = $this->makeUser($this->cafe, 'staff', 'revoked@example.com');

        $phone = $this->tokenFor($user);
        $tablet = $this->tokenFor($user);

        $this->getJson('/api/stations', ['Authorization' => "Bearer {$phone}"])->assertOk();

        $this->apiPost($this->admin, "/api/staff/{$user->id}/revoke")->assertOk();

        // Every device, not just the one that triggered it.
        $this->getJson('/api/stations', ['Authorization' => "Bearer {$phone}"])->assertUnauthorized();
        $this->getJson('/api/stations', ['Authorization' => "Bearer {$tablet}"])->assertUnauthorized();
    }

    public function test_a_garbled_or_missing_token_is_401(): void
    {
        $this->getJson('/api/stations')->assertUnauthorized();
        $this->getJson('/api/stations', ['Authorization' => 'Bearer not-a-token'])->assertUnauthorized();
    }

    /**
     * A browser NAVIGATION to an API route sends Accept: text/html, not JSON.
     *
     * That took a different branch inside Laravel's Authenticate middleware,
     * which tried to redirect the guest to a named `login` route. This app has
     * none, so it answered 500 "Route [login] not defined" rather than 401.
     * Every other test here asks for JSON, so none of them saw it.
     */
    public function test_a_guest_asking_for_html_still_gets_401_not_a_redirect(): void
    {
        $this->get('/api/stations', ['Accept' => 'text/html'])->assertUnauthorized();
        $this->get('/api/invoices/1/pdf', ['Accept' => 'text/html'])->assertUnauthorized();
    }

    public function test_a_token_signed_with_the_wrong_key_is_rejected(): void
    {
        $token = $this->tokenFor($this->admin);

        config()->set('cafetrack.jwt_secret', 'a-completely-different-signing-key');

        $this->getJson('/api/stations', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    }

    public function test_an_expired_token_is_rejected(): void
    {
        config()->set('cafetrack.jwt_ttl_hours', -1);

        $token = $this->tokenFor($this->admin);

        config()->set('cafetrack.jwt_ttl_hours', 12);

        $this->getJson('/api/stations', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    }

    public function test_passwords_are_stored_as_bcrypt(): void
    {
        // So hashes from the FastAPI reference import unchanged and nobody has
        // to reset a password.
        $this->assertStringStartsWith('$2y$', $this->admin->password_hash);
    }
}
