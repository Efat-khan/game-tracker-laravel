<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cafe_id')->constrained('cafes');
            $table->string('name', 150);
            $table->string('phone_or_id', 100);
            $table->decimal('balance', 10, 2)->default(0);
            $table->dateTime('created_at')->nullable();

            $table->index('cafe_id');
            // The same phone in two cafes is two separate customers (§4.8).
            $table->index(['cafe_id', 'phone_or_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
