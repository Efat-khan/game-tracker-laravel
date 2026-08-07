<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Optional modules the platform owner grants a cafe.
 *
 * Everything not listed here is core and always on — a cafe without stations,
 * sessions, invoices or a cash drawer is not a cafe. These three are genuinely
 * optional: plenty of places rent screens without selling crisps, running a
 * loyalty scheme, or taking reservations.
 */
class FeatureService
{
    /** @var array<string, array{label:string, blurb:string}> */
    public const CATALOGUE = [
        'products' => [
            'label' => 'Products',
            'blurb' => 'Sell snacks and drinks, and add them to a bill.',
        ],
        'loyalty' => [
            'label' => 'Loyalty',
            'blurb' => 'Top-up packages and membership tiers with automatic discounts.',
        ],
        'bookings' => [
            'label' => 'Bookings',
            'blurb' => 'Reserve a station for a future slot.',
        ],
    ];

    /** @var array<int, array<string,bool>> memo, per request */
    private array $memo = [];

    /** @return array<string,bool> every known feature, resolved for this cafe */
    public function all(int $cafeId): array
    {
        if (isset($this->memo[$cafeId])) {
            return $this->memo[$cafeId];
        }

        $stored = DB::table('cafe_features')
            ->where('cafe_id', $cafeId)
            ->pluck('enabled', 'feature')
            ->all();

        $resolved = [];

        foreach (array_keys(self::CATALOGUE) as $feature) {
            // Absent means enabled: the owner disables, rather than having to
            // grant each module to every cafe that already had it.
            $resolved[$feature] = array_key_exists($feature, $stored)
                ? (bool) $stored[$feature]
                : true;
        }

        return $this->memo[$cafeId] = $resolved;
    }

    public function enabled(int $cafeId, string $feature): bool
    {
        // An unknown name is core, not optional — a typo in a route must never
        // silently switch something off.
        if (! array_key_exists($feature, self::CATALOGUE)) {
            return true;
        }

        return $this->all($cafeId)[$feature];
    }

    /**
     * Superadmin only. Unknown keys are ignored rather than stored, so the
     * table cannot fill up with names nothing checks.
     *
     * @param  array<string,bool>  $values
     * @return array<string,bool>
     */
    public function put(int $cafeId, array $values): array
    {
        foreach ($values as $feature => $enabled) {
            if (! array_key_exists($feature, self::CATALOGUE)) {
                continue;
            }

            DB::table('cafe_features')->updateOrInsert(
                ['cafe_id' => $cafeId, 'feature' => $feature],
                ['enabled' => (bool) $enabled],
            );
        }

        unset($this->memo[$cafeId]);

        return $this->all($cafeId);
    }

    /** The catalogue plus this cafe's state, for the owner's switches. */
    public function describe(int $cafeId): array
    {
        $state = $this->all($cafeId);

        return array_map(
            fn (string $key) => [
                'key' => $key,
                'label' => self::CATALOGUE[$key]['label'],
                'blurb' => self::CATALOGUE[$key]['blurb'],
                'enabled' => $state[$key],
            ],
            array_keys(self::CATALOGUE),
        );
    }
}
