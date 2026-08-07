<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only (§5.6).
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cafe_id')->constrained('cafes');
            $table->foreignId('actor_id')->nullable()->constrained('admin_users');
            $table->string('actor_email', 150)->default('customer');
            $table->string('actor_role', 20)->default('public');
            $table->string('action', 50);
            $table->string('entity_type', 30);
            $table->integer('entity_id')->nullable();
            $table->string('summary', 300)->default('');
            $table->dateTime('created_at')->nullable();

            $table->index('cafe_id');
            $table->index(['cafe_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
