<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The ledger is the audit trail; customers.balance is a cache of it (§5.3).
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cafe_id')->constrained('cafes');
            $table->foreignId('customer_id')->constrained('customers');
            $table->string('kind', 20);
            $table->decimal('amount', 10, 2); // signed
            $table->decimal('balance_after', 10, 2);
            $table->foreignId('invoice_id')->nullable()->constrained('invoices');
            $table->foreignId('package_id')->nullable()->constrained('packages');
            $table->string('note', 200)->default('');
            $table->string('actor_email', 150)->default('');
            $table->string('payment_method', 20)->nullable();
            $table->foreignId('shift_id')->nullable()->constrained('shifts');
            $table->dateTime('created_at')->nullable();

            $table->index('cafe_id');
            $table->index('customer_id');
            $table->index('shift_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
