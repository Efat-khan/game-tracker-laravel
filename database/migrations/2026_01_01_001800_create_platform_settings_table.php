<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Platform-wide, deliberately NOT keyed on cafe_id.
         *
         * app_settings is per cafe because billing rules are. These are not:
         * the login page and the logo are what somebody sees BEFORE they have
         * authenticated, when the app has no idea which cafe they belong to.
         * There is one of each, and the platform owner sets it.
         */
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 50)->primary();
            $table->string('value', 300);

            // Who last changed it. There is no audit row for these — audit_events
            // is scoped to a cafe and a superadmin is inside none — so the trail
            // lives here.
            $table->string('updated_by_email', 191)->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
