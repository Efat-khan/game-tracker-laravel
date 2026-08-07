<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cafe_id')->constrained('cafes');
            $table->string('name', 100);
            $table->string('type', 50);
            $table->decimal('hourly_rate', 10, 2);
            $table->decimal('extra_controller_rate', 10, 2)->default(0);
            $table->integer('max_controllers')->default(4);
            $table->text('qr_code_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('maintenance')->default(false);
            $table->dateTime('created_at')->nullable();

            $table->index('cafe_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stations');
    }
};
