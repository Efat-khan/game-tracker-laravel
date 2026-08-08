<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * What the cafe spends, as opposed to what leaves the till.
         *
         * Those are not the same thing and the difference is the reason this
         * table exists. `cash_movements` reconciles the DRAWER — it holds the
         * float being topped up, the takings being banked, anything that
         * changes what is physically in the till. This holds the BUSINESS's
         * costs, including the ones the till never sees: rent by bank
         * transfer, an online supplier bill, a salary.
         *
         * The two meet at one point: an expense paid in cash writes a matching
         * cash movement and points at it, so the drawer maths is unchanged and
         * there is still exactly one source of truth for the till.
         */
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cafe_id')->constrained('cafes');

            // Set only when the money came out of an open drawer.
            $table->foreignId('shift_id')->nullable()->constrained('shifts');
            $table->foreignId('cash_movement_id')->nullable()->constrained('cash_movements');

            $table->string('category', 40);
            $table->decimal('amount', 10, 2);
            $table->string('payment_method', 20);
            $table->string('note', 200)->default('');

            // The day the cost belongs to, which is not always the day it was
            // typed in — a bill can be entered late. Every report groups on
            // this, never on created_at.
            $table->date('spent_on');

            $table->string('actor_email', 150)->default('');
            $table->dateTime('created_at')->nullable();

            $table->index(['cafe_id', 'spent_on']);
            $table->index('shift_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
