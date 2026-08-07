<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cafe_id')->constrained('cafes');
            // Unique: ending a session creates exactly one invoice (§5.2).
            $table->foreignId('session_id')->unique()->constrained('sessions');
            $table->decimal('total_amount', 10, 2);
            $table->decimal('session_amount', 10, 2)->default(0);
            $table->decimal('items_amount', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->string('discount_reason', 200)->nullable();
            $table->integer('duration_minutes');
            $table->string('payment_method', 20)->nullable();
            $table->string('payment_status', 20)->default('unpaid');
            $table->string('status', 20)->default('active');
            $table->string('void_reason', 200)->nullable();
            $table->dateTime('paid_at')->nullable();
            // The shift that COLLECTED the money, not the one that started
            // the session (§5.4).
            $table->foreignId('shift_id')->nullable()->constrained('shifts');
            $table->dateTime('created_at')->nullable();

            $table->index('cafe_id');
            $table->index(['cafe_id', 'status']);
            $table->index(['cafe_id', 'payment_status']);
            $table->index('shift_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
