<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cafe_id')->constrained('cafes');
            $table->foreignId('opened_by_id')->nullable()->constrained('admin_users');
            $table->string('opened_by_email', 150);
            $table->dateTime('opened_at');
            $table->decimal('opening_float', 10, 2)->default(0);
            $table->string('open_note', 200)->default('');
            $table->foreignId('closed_by_id')->nullable()->constrained('admin_users');
            $table->string('closed_by_email', 150)->nullable();
            $table->dateTime('closed_at')->nullable();
            // Frozen at close time so a later void cannot rewrite a signed-off
            // reconciliation (§5.4).
            $table->decimal('counted_cash', 10, 2)->nullable();
            $table->decimal('expected_cash', 10, 2)->nullable();
            $table->decimal('variance', 10, 2)->nullable();
            $table->string('close_note', 200)->default('');
            $table->string('status', 20)->default('open');

            $table->index('cafe_id');
            $table->index(['cafe_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
