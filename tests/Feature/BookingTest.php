<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Cafe;
use App\Models\Station;
use Tests\TestCase;

/** §5.5 — reservations. */
class BookingTest extends TestCase
{
    private Cafe $cafe;

    private AdminUser $staff;

    private Station $station;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cafe = $this->makeCafe();
        $this->staff = $this->makeUser($this->cafe, 'staff', 'staff@example.com');
        $this->station = $this->makeStation($this->cafe);
    }

    private function at(int $hour, int $minute = 0): string
    {
        return now()->addDay()->setTime($hour, $minute)->format('Y-m-d H:i:s');
    }

    private function book(int $fromHour, int $toHour, string $name = 'Rafi Ahmed', ?int $stationId = null)
    {
        return $this->apiPost($this->staff, '/api/bookings', [
            'station_id' => $stationId ?? $this->station->id,
            'customer_name' => $name,
            'customer_phone' => '01711111111',
            'starts_at' => $this->at($fromHour),
            'ends_at' => $this->at($toHour),
        ]);
    }

    public function test_a_reservation_can_be_made(): void
    {
        $booking = $this->book(18, 20)->assertCreated()->json();

        $this->assertSame('booked', $booking['status']);
        $this->assertSame('Rafi Ahmed', $booking['customer_name']);
        $this->assertSame($this->station->id, $booking['station_id']);
    }

    public function test_an_overlapping_slot_is_refused_and_names_the_holder(): void
    {
        $this->book(18, 20, 'Rafi Ahmed')->assertCreated();

        $response = $this->book(19, 21, 'Nabila Haque');

        $response->assertStatus(409);
        // The message must say who already has it and when.
        $this->assertStringContainsString('Rafi Ahmed', $response->json('message'));
        $this->assertStringContainsString('18:00', $response->json('message'));
    }

    public function test_a_slot_entirely_inside_another_is_refused(): void
    {
        $this->book(18, 22)->assertCreated();

        $this->book(19, 20, 'Nabila Haque')->assertStatus(409);
    }

    public function test_a_slot_that_swallows_another_is_refused(): void
    {
        $this->book(19, 20)->assertCreated();

        $this->book(18, 22, 'Nabila Haque')->assertStatus(409);
    }

    public function test_adjacent_slots_are_allowed(): void
    {
        // 18:00-20:00 then 20:00-22:00 touch but do not overlap.
        $this->book(18, 20)->assertCreated();
        $this->book(20, 22, 'Nabila Haque')->assertCreated();
    }

    public function test_the_same_slot_on_a_different_station_is_allowed(): void
    {
        $second = $this->makeStation($this->cafe, ['name' => 'PS5 - Booth 2']);

        $this->book(18, 20)->assertCreated();
        $this->book(18, 20, 'Nabila Haque', $second->id)->assertCreated();
    }

    public function test_a_cancelled_booking_frees_its_slot(): void
    {
        $booking = $this->book(18, 20)->assertCreated()->json();

        $this->apiPost($this->staff, "/api/bookings/{$booking['id']}/cancel")->assertOk();

        $this->book(18, 20, 'Nabila Haque')->assertCreated();
    }

    public function test_arrived_converts_the_reservation_into_a_session(): void
    {
        $booking = $this->book(18, 20)->assertCreated()->json();

        $result = $this->apiPost($this->staff, "/api/bookings/{$booking['id']}/start")
            ->assertCreated()->json();

        $this->assertSame('arrived', $result['booking']['status']);
        $this->assertNotNull($result['booking']['session_id']);
        // The booked length carries across as planned_minutes.
        $this->assertSame(120, $result['session']['planned_minutes']);
        $this->assertSame('Rafi Ahmed', $result['session']['customer_name']);
    }

    public function test_a_session_past_its_planned_minutes_is_flagged_overdue(): void
    {
        $customer = $this->makeCustomer($this->cafe);

        $this->makeSession($this->cafe, $this->station, $customer, 75, ['planned_minutes' => 60]);

        $row = collect($this->apiGet($this->staff, '/api/sessions/active')->json())
            ->firstWhere('station_id', $this->station->id);

        $this->assertSame(60, $row['planned_minutes']);
        $this->assertSame(15, $row['overdue_minutes']);
    }

    public function test_a_booking_cannot_start_on_an_occupied_station(): void
    {
        $customer = $this->makeCustomer($this->cafe);
        $this->makeSession($this->cafe, $this->station, $customer, 10);

        $booking = $this->book(18, 20)->assertCreated()->json();

        $this->apiPost($this->staff, "/api/bookings/{$booking['id']}/start")->assertStatus(409);

        $this->assertDatabaseHas('bookings', ['id' => $booking['id'], 'status' => 'booked']);
    }

    public function test_a_booking_can_be_marked_a_no_show(): void
    {
        $booking = $this->book(18, 20)->assertCreated()->json();

        $this->apiPost($this->staff, "/api/bookings/{$booking['id']}/cancel", ['no_show' => true])
            ->assertOk()->assertJson(['status' => 'no_show']);
    }

    public function test_a_booking_can_be_cancelled(): void
    {
        $booking = $this->book(18, 20)->assertCreated()->json();

        $this->apiPost($this->staff, "/api/bookings/{$booking['id']}/cancel")
            ->assertOk()->assertJson(['status' => 'cancelled']);
    }

    public function test_a_booking_that_is_no_longer_on_the_books_cannot_be_started_or_cancelled(): void
    {
        $booking = $this->book(18, 20)->assertCreated()->json();

        $this->apiPost($this->staff, "/api/bookings/{$booking['id']}/cancel")->assertOk();

        $this->apiPost($this->staff, "/api/bookings/{$booking['id']}/cancel")->assertStatus(400);
        $this->apiPost($this->staff, "/api/bookings/{$booking['id']}/start")->assertStatus(400);
    }

    public function test_a_reservation_can_be_rescheduled(): void
    {
        $booking = $this->book(18, 20)->assertCreated()->json();

        $moved = $this->apiPatch($this->staff, "/api/bookings/{$booking['id']}", [
            'starts_at' => $this->at(21),
            'ends_at' => $this->at(23),
        ])->assertOk()->json();

        $this->assertStringContainsString('21:00', $moved['starts_at']);

        // Rescheduling onto its own old slot is not a clash with itself.
        $this->apiPatch($this->staff, "/api/bookings/{$booking['id']}", [
            'starts_at' => $this->at(21),
            'ends_at' => $this->at(22),
        ])->assertOk();
    }

    public function test_rescheduling_onto_an_occupied_slot_is_refused(): void
    {
        $first = $this->book(18, 20)->assertCreated()->json();
        $second = $this->book(21, 23, 'Nabila Haque')->assertCreated()->json();

        $this->apiPatch($this->staff, "/api/bookings/{$second['id']}", [
            'starts_at' => $this->at(19),
            'ends_at' => $this->at(20),
        ])->assertStatus(409);

        $this->assertNotNull($first['id']);
    }

    public function test_bookings_can_be_filtered_by_status_and_day(): void
    {
        $this->book(18, 20)->assertCreated();
        $cancelled = $this->book(21, 23, 'Nabila Haque')->assertCreated()->json();

        $this->apiPost($this->staff, "/api/bookings/{$cancelled['id']}/cancel")->assertOk();

        $booked = $this->apiGet($this->staff, '/api/bookings?status=booked')->json();
        $this->assertCount(1, $booked);

        $onDay = $this->apiGet($this->staff, '/api/bookings?on='.now()->addDay()->format('Y-m-d'))->json();
        $this->assertCount(2, $onDay);

        $today = $this->apiGet($this->staff, '/api/bookings?on='.now()->format('Y-m-d'))->json();
        $this->assertCount(0, $today);
    }

    public function test_ends_at_must_be_after_starts_at(): void
    {
        $this->apiPost($this->staff, '/api/bookings', [
            'station_id' => $this->station->id,
            'customer_name' => 'Rafi Ahmed',
            'customer_phone' => '01711111111',
            'starts_at' => $this->at(20),
            'ends_at' => $this->at(18),
        ])->assertStatus(422);
    }
}
