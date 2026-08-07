<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keyed on (cafe_id, key) so two cafes hold different billing rules (§4.7).
        Schema::create('app_settings', function (Blueprint $table) {
            $table->foreignId('cafe_id')->constrained('cafes');
            $table->string('key', 50);
            $table->string('value', 200);

            $table->primary(['cafe_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
