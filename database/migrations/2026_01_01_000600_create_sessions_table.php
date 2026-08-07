<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cafe_id')->constrained('cafes');
            $table->foreignId('station_id')->constrained('stations');
            $table->foreignId('customer_id')->constrained('customers');
            $table->dateTime('start_time');
            $table->dateTime('end_time')->nullable();
            $table->string('status', 20);
            // Rate snapshots taken at check-in: a later price change must never
            // alter a session already running or an invoice already issued (§5.1).
            $table->decimal('hourly_rate_snapshot', 10, 2);
            $table->integer('controllers')->default(1);
            $table->decimal('base_rate_snapshot', 10, 2)->default(0);
            $table->decimal('extra_controller_rate_snapshot', 10, 2)->default(0);
            $table->integer('planned_minutes')->nullable();
            $table->dateTime('created_at')->nullable();

            $table->index('cafe_id');
            $table->index('station_id');
            $table->index('status');
            // Serves the "does this station already have an active session" check.
            $table->index(['station_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
