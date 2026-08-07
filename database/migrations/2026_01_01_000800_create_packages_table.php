<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cafe_id')->constrained('cafes');
            $table->string('name', 100);
            $table->decimal('price', 10, 2);
            $table->decimal('credit', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->nullable();

            $table->index('cafe_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
