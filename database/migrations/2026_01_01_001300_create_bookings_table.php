<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cafe_id')->constrained('cafes');
            $table->foreignId('station_id')->constrained('stations');
            $table->string('customer_name', 150);
            $table->string('customer_phone', 100);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->integer('controllers')->default(1);
            $table->string('status', 20)->default('booked');
            $table->string('note', 200)->default('');
            $table->foreignId('session_id')->nullable()->constrained('sessions');
            $table->string('created_by_email', 150)->default('');
            $table->dateTime('created_at')->nullable();

            $table->index('cafe_id');
            $table->index('station_id');
            // Serves the overlap check: same station, status='booked'.
            $table->index(['station_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
