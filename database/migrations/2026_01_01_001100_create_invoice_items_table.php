<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No cafe_id: an item is scoped through its invoice (§8).
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices');
            $table->foreignId('product_id')->nullable()->constrained('products');
            $table->string('description', 150);
            $table->integer('quantity')->default(1);
            // Both price AND cost are snapshotted at sale time so profit reports
            // stay correct after supplier prices change (§5.2).
            $table->decimal('unit_price', 10, 2);
            $table->decimal('unit_cost', 10, 2)->default(0);
            $table->decimal('amount', 10, 2);
            $table->dateTime('created_at')->nullable();

            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
