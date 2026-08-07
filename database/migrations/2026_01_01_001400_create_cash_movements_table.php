<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Scoped through its shift, which carries the cafe_id.
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained('shifts');
            $table->string('kind', 10); // in | out
            $table->decimal('amount', 10, 2);
            $table->string('reason', 200)->default('');
            $table->string('actor_email', 150)->default('');
            $table->dateTime('created_at')->nullable();

            $table->index('shift_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
