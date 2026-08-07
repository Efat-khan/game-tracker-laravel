<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cafe_id')->constrained('cafes');
            $table->string('name', 100);
            $table->string('category', 50)->default('Snacks');
            $table->decimal('price', 10, 2);
            $table->decimal('cost_price', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->nullable();

            $table->index('cafe_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
