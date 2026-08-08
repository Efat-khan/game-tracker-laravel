<?php

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One hourly rate per controller count, rather than a base plus a flat
         * charge per extra pad.
         *
         * The old model could only express prices that step evenly. Real price
         * lists do not: a cafe charging 100 / 120 / 160 / 200 for one to four
         * players adds 20 for the second pad and 40 for the third and fourth,
         * and no single "extra controller" figure reproduces that.
         *
         * There is a row for every count from 1 to the station's
         * max_controllers, so a lookup is a lookup and never arithmetic.
         */
        Schema::create('station_rates', function (Blueprint $table) {
            $table->foreignId('station_id')->constrained('stations')->cascadeOnDelete();
            $table->unsignedTinyInteger('controllers');
            $table->decimal('hourly_rate', 10, 2);

            $table->primary(['station_id', 'controllers']);
        });

        // Carry every existing station over on the old formula, so nothing
        // loses its pricing on the way across.
        foreach (DB::table('stations')->get() as $station) {
            $rows = [];

            for ($n = 1; $n <= max(1, (int) $station->max_controllers); $n++) {
                $rows[] = [
                    'station_id' => $station->id,
                    'controllers' => $n,
                    'hourly_rate' => (string) BigDecimal::of((string) $station->hourly_rate)
                        ->plus(BigDecimal::of((string) $station->extra_controller_rate)->multipliedBy($n - 1))
                        ->toScale(2, RoundingMode::HalfUp),
                ];
            }

            DB::table('station_rates')->insert($rows);
        }

        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn('extra_controller_rate');
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->decimal('extra_controller_rate', 10, 2)->default(0);
        });

        // Best effort: the difference between one and two controllers is the
        // only thing the old shape could hold.
        foreach (DB::table('stations')->get() as $station) {
            $two = DB::table('station_rates')
                ->where('station_id', $station->id)
                ->where('controllers', 2)
                ->value('hourly_rate');

            DB::table('stations')->where('id', $station->id)->update([
                'extra_controller_rate' => $two === null
                    ? '0.00'
                    : (string) BigDecimal::of((string) $two)
                        ->minus((string) $station->hourly_rate)
                        ->toScale(2, RoundingMode::HalfUp),
            ]);
        }

        Schema::dropIfExists('station_rates');
    }
};
