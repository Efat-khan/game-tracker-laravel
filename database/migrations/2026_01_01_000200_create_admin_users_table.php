<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            // NULLABLE WITH NO DEFAULT. A superadmin has no cafe; a column default
            // here would silently file the platform owner into cafe 1. See §4.4.
            $table->foreignId('cafe_id')->nullable()->constrained('cafes');
            $table->string('email', 150)->unique();
            $table->text('password_hash');
            $table->string('role', 20);
            $table->integer('token_version')->default(0);
            $table->dateTime('created_at')->nullable();

            $table->index('cafe_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_users');
    }
};
