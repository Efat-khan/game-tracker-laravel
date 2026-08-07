<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Which optional modules the platform owner has granted a cafe.
         *
         * Deliberately NOT stored in app_settings: that table is writable by a
         * cafe's own admin, and a cafe must not be able to switch on a module
         * it was not given. Only a superadmin writes here.
         *
         * An absent row means enabled, so existing cafes keep everything they
         * already had and the owner turns things OFF rather than having to
         * grant each one.
         */
        Schema::create('cafe_features', function (Blueprint $table) {
            $table->foreignId('cafe_id')->constrained('cafes')->cascadeOnDelete();
            $table->string('feature', 40);
            $table->boolean('enabled')->default(true);

            $table->primary(['cafe_id', 'feature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cafe_features');
    }
};
