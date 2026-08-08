<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Cafe;
use App\Models\CashMovement;
use App\Models\Expense;
use Tests\TestCase;

/**
 * The expense ledger, and the one rule that ties it to the cash drawer.
 *
 * `cash_movements` reconciles the TILL; `expenses` records what the business
 * SPENDS. The pair only meet when an expense is paid in cash, and most of what
 * is tested here is that boundary holding in both directions.
 */
class ExpenseTest extends TestCase
{
    private Cafe $cafe;

    private AdminUser $admin;

    private AdminUser $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cafe = $this->makeCafe();
        $this->admin = $this->makeUser($this->cafe, 'admin', 'admin@example.com');
        $this->staff = $this->makeUser($this->cafe, 'staff', 'staff@example.com');
    }

    private function openShift(?AdminUser $as = null): int
    {
        return $this->apiPost($as ?? $this->admin, '/api/shifts/open', ['opening_float' => '1000'])
            ->assertCreated()
            ->json('id');
    }

    private function record(array $overrides = [], ?AdminUser $as = null)
    {
        return $this->apiPost($as ?? $this->admin, '/api/expenses', array_merge([
            'category' => 'stock',
            'amount' => '250',
            'payment_method' => 'bank',
            'note' => 'Soft drinks',
        ], $overrides));
    }

    /* --------------------------------------------------------- recording */

    public function test_an_expense_is_recorded_against_the_cafe(): void
    {
        $this->record()->assertCreated()
            ->assertJsonPath('category', 'stock')
            ->assertJsonPath('category_label', 'Stock & supplies')
            ->assertJsonPath('amount', '250.00')
            ->assertJsonPath('payment_method', 'bank')
            ->assertJsonPath('spent_on', now()->toDateString())
            ->assertJsonPath('actor_email', 'admin@example.com');

        $this->assertSame(1, Expense::where('cafe_id', $this->cafe->id)->count());
    }

    public function test_staff_can_record_an_expense(): void
    {
        // They are the ones sent out for change and batteries.
        $this->record(['payment_method' => 'bank'], $this->staff)->assertCreated();
    }

    public function test_a_guest_cannot_record_an_expense(): void
    {
        $this->postJson('/api/expenses', ['category' => 'stock', 'amount' => '10', 'payment_method' => 'bank'])
            ->assertUnauthorized();
    }

    public function test_a_non_cash_expense_never_touches_the_drawer(): void
    {
        $this->openShift();

        $this->record(['payment_method' => 'bank'])->assertCreated()->assertJsonPath('shift_id', null);

        $this->assertSame(0, CashMovement::count());
    }

    public function test_a_cash_expense_comes_out_of_the_open_drawer(): void
    {
        $shift = $this->openShift();

        $id = $this->record(['payment_method' => 'cash', 'amount' => '250', 'note' => 'Soft drinks'])
            ->assertCreated()
            ->assertJsonPath('shift_id', $shift)
            ->json('id');

        $movement = CashMovement::firstOrFail();

        $this->assertSame('out', $movement->kind);
        $this->assertSame('250.00', $movement->amount);
        // The drawer's own record says why, so the Shifts screen reads alone.
        $this->assertSame('Stock & supplies — Soft drinks', $movement->reason);
        $this->assertSame($movement->id, Expense::findOrFail($id)->cash_movement_id);
    }

    public function test_a_cash_expense_lowers_the_expected_cash(): void
    {
        // This is the point of writing the movement at all: the drawer has to
        // balance at closing.
        $shift = $this->openShift();

        $before = $this->apiGet($this->admin, "/api/shifts/{$shift}")->assertOk()->json('expected_cash');
        $this->assertSame('1000.00', $before);

        $this->record(['payment_method' => 'cash', 'amount' => '250'])->assertCreated();

        $this->apiGet($this->admin, "/api/shifts/{$shift}")
            ->assertOk()
            ->assertJsonPath('expected_cash', '750.00');
    }

    public function test_paying_cash_with_no_shift_open_is_refused(): void
    {
        $this->record(['payment_method' => 'cash'])
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'shift has to be open'));

        $this->assertSame(0, Expense::count());
        $this->assertSame(0, CashMovement::count());
    }

    public function test_a_cash_expense_is_always_dated_today(): void
    {
        // The money left the drawer that is open now; yesterday's has already
        // been counted and signed off.
        $this->openShift();

        $this->record(['payment_method' => 'cash', 'spent_on' => now()->subDays(3)->toDateString()])
            ->assertCreated()
            ->assertJsonPath('spent_on', now()->toDateString());
    }

    public function test_a_non_cash_expense_can_be_dated_earlier(): void
    {
        // A bill gets entered late, and belongs to the day it was incurred.
        $this->record(['payment_method' => 'bank', 'spent_on' => now()->subDays(5)->toDateString()])
            ->assertCreated()
            ->assertJsonPath('spent_on', now()->subDays(5)->toDateString());
    }

    /* -------------------------------------------------------- validation */

    public function test_an_unknown_category_is_refused(): void
    {
        $this->record(['category' => 'bribes'])->assertStatus(422)->assertJsonValidationErrors('category');
    }

    public function test_an_unknown_payment_method_is_refused(): void
    {
        $this->record(['payment_method' => 'crypto'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');
    }

    public function test_a_free_or_negative_expense_is_refused(): void
    {
        $this->record(['amount' => '0'])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->record(['amount' => '-50'])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_a_future_expense_is_refused(): void
    {
        // A report that includes money not yet spent is one nobody can
        // reconcile.
        $this->record(['spent_on' => now()->addDay()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('spent_on');
    }

    /* ------------------------------------------------------------ reading */

    public function test_the_ledger_totals_and_groups_by_category(): void
    {
        foreach ([['stock', '40'], ['stock', '60'], ['utilities', '30']] as [$category, $amount]) {
            $this->record(['category' => $category, 'amount' => $amount])->assertCreated();
        }

        $body = $this->apiGet($this->admin, '/api/expenses')->assertOk()->json();

        $this->assertSame('130.00', $body['total']);
        $this->assertCount(3, $body['expenses']);

        $byCategory = collect($body['by_category'])->keyBy('category');
        $this->assertSame('100.00', $byCategory['stock']['total']);
        $this->assertSame(2, $byCategory['stock']['count']);
        $this->assertSame('30.00', $byCategory['utilities']['total']);
    }

    public function test_the_ledger_ships_the_category_catalogue(): void
    {
        // So the form and the validator cannot drift apart on what is valid.
        $body = $this->apiGet($this->admin, '/api/expenses')->assertOk()->json();

        $this->assertSame(array_keys(Expense::CATEGORIES), collect($body['categories'])->pluck('key')->all());
        $this->assertSame(Expense::METHODS, $body['methods']);
    }

    public function test_the_ledger_reads_a_window(): void
    {
        $this->record(['amount' => '10', 'spent_on' => now()->subDays(40)->toDateString()])->assertCreated();
        $this->record(['amount' => '20', 'spent_on' => now()->toDateString()])->assertCreated();

        // Today is inside the window even though it is the upper bound.
        $this->apiGet($this->admin, '/api/expenses?from='.now()->subDays(7)->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('total', '20.00');

        $this->apiGet($this->admin, '/api/expenses?from='.now()->subDays(60)->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('total', '30.00');
    }

    public function test_the_ledger_filters_by_category(): void
    {
        $this->record(['category' => 'stock', 'amount' => '40'])->assertCreated();
        $this->record(['category' => 'rent', 'amount' => '500'])->assertCreated();

        $this->apiGet($this->admin, '/api/expenses?category=rent')
            ->assertOk()
            ->assertJsonPath('total', '500.00')
            ->assertJsonCount(1, 'expenses');
    }

    public function test_one_cafe_never_sees_anothers_ledger(): void
    {
        $other = $this->makeCafe('Other Cafe');
        $otherAdmin = $this->makeUser($other, 'admin', 'other@example.com');

        $this->record(['amount' => '999'])->assertCreated();

        $this->apiGet($otherAdmin, '/api/expenses')
            ->assertOk()
            ->assertJsonPath('total', '0.00')
            ->assertJsonCount(0, 'expenses');
    }

    /* ------------------------------------------------------------ deleting */

    public function test_an_admin_can_delete_an_expense(): void
    {
        $id = $this->record()->assertCreated()->json('id');

        $this->apiDelete($this->admin, "/api/expenses/{$id}")->assertNoContent();

        $this->assertSame(0, Expense::count());
    }

    public function test_deleting_a_cash_expense_puts_the_money_back_in_the_drawer(): void
    {
        $shift = $this->openShift();
        $id = $this->record(['payment_method' => 'cash', 'amount' => '250'])->assertCreated()->json('id');

        $this->apiDelete($this->admin, "/api/expenses/{$id}")->assertNoContent();

        $this->assertSame(0, CashMovement::count());
        $this->apiGet($this->admin, "/api/shifts/{$shift}")
            ->assertOk()
            ->assertJsonPath('expected_cash', '1000.00');
    }

    public function test_an_expense_cannot_be_deleted_once_its_shift_is_counted(): void
    {
        // The same rule that freezes a closed shift's expected_cash: a
        // signed-off drawer must not move afterwards.
        $shift = $this->openShift();
        $id = $this->record(['payment_method' => 'cash', 'amount' => '250'])->assertCreated()->json('id');

        $this->apiPost($this->admin, "/api/shifts/{$shift}/close", ['counted_cash' => '750'])->assertOk();

        $this->apiDelete($this->admin, "/api/expenses/{$id}")
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'closed and counted'));

        $this->assertSame(1, Expense::count());
    }

    public function test_staff_cannot_delete_an_expense(): void
    {
        $id = $this->record()->assertCreated()->json('id');

        $this->apiDelete($this->staff, "/api/expenses/{$id}")->assertForbidden();

        $this->assertSame(1, Expense::count());
    }

    public function test_an_admin_cannot_delete_another_cafes_expense(): void
    {
        $other = $this->makeCafe('Other Cafe');
        $otherAdmin = $this->makeUser($other, 'admin', 'other@example.com');

        $id = $this->record()->assertCreated()->json('id');

        // 404, not 403 — a lookup must not confirm another tenant's row exists.
        $this->apiDelete($otherAdmin, "/api/expenses/{$id}")->assertNotFound();

        $this->assertSame(1, Expense::count());
    }

    /* --------------------------------------------------------------- audit */

    public function test_recording_and_deleting_are_both_logged(): void
    {
        $id = $this->record(['amount' => '250'])->assertCreated()->json('id');
        $this->apiDelete($this->admin, "/api/expenses/{$id}")->assertNoContent();

        $actions = collect($this->apiGet($this->admin, '/api/audit')->assertOk()->json())
            ->pluck('action');

        $this->assertTrue($actions->contains('expense_create'));
        $this->assertTrue($actions->contains('expense_delete'));
    }
}
