<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Per-cafe billing rules. app_settings is keyed on (cafe_id, key), so two cafes
 * hold genuinely different rules and one cafe's change never touches another
 * (§4.7).
 */
class SettingsService
{
    /** @var array<int, array<string,int>> memo, per request */
    private array $memo = [];

    /** @return array<string,int> */
    public function all(int $cafeId): array
    {
        if (isset($this->memo[$cafeId])) {
            return $this->memo[$cafeId];
        }

        $defaults = config('cafetrack.settings_defaults');

        $stored = DB::table('app_settings')
            ->where('cafe_id', $cafeId)
            ->pluck('value', 'key')
            ->all();

        $values = [];

        foreach ($defaults as $key => $default) {
            $values[$key] = isset($stored[$key]) && is_numeric($stored[$key])
                ? (int) $stored[$key]
                : (int) $default;
        }

        return $this->memo[$cafeId] = $values;
    }

    public function get(int $cafeId, string $key): int
    {
        return $this->all($cafeId)[$key] ?? (int) config("cafetrack.settings_defaults.{$key}");
    }

    public function billingRoundMinutes(int $cafeId): int
    {
        return max(1, $this->get($cafeId, 'billing_round_minutes'));
    }

    public function roundAmountTo(int $cafeId): int
    {
        return max(1, $this->get($cafeId, 'round_amount_to'));
    }

    /**
     * Trading hours, used as the denominator for station utilization — capacity
     * is the hours the cafe is actually open, not 24 (§6.4).
     *
     * A stored pair where close does not exceed open is nonsense and would make
     * utilization negative or infinite, so it falls back to 10/23.
     *
     * @return array{open:int, close:int}
     */
    public function tradingHours(int $cafeId): array
    {
        $values = $this->all($cafeId);
        $open = $values['open_hour'];
        $close = $values['close_hour'];

        if ($close <= $open) {
            return ['open' => 10, 'close' => 23];
        }

        return ['open' => $open, 'close' => $close];
    }

    /**
     * @param  array<string,int>  $values
     * @return array<string,int>
     */
    public function put(int $cafeId, array $values): array
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, config('cafetrack.settings_defaults'))) {
                continue;
            }

            DB::table('app_settings')->updateOrInsert(
                ['cafe_id' => $cafeId, 'key' => $key],
                ['value' => (string) (int) $value],
            );
        }

        unset($this->memo[$cafeId]);

        return $this->all($cafeId);
    }
}
