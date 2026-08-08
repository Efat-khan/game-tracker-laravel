<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Station extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'cafe_id', 'name', 'type', 'hourly_rate',
        'max_controllers', 'qr_code_url', 'is_active', 'maintenance',
    ];

    protected $casts = [
        'hourly_rate' => 'decimal:2',
        'max_controllers' => 'integer',
        'is_active' => 'boolean',
        'maintenance' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function sessions(): HasMany
    {
        return $this->hasMany(GameSession::class, 'station_id');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(StationRate::class, 'station_id');
    }

    /**
     * The price list as [controllers => rate], which is the shape both the
     * billing engine and the API want.
     *
     * `hourly_rate` on the station itself is the one-controller price, kept in
     * step with the rates table. It stays because it is the headline figure on
     * the stations list and the public check-in page, and because it is the
     * fallback if a count somehow has no row.
     */
    public function rateMap(): array
    {
        // Sorted in PHP rather than SQL so an eager-loaded `rates` relation is
        // reused instead of firing a fresh query per station.
        return $this->rates
            ->sortBy('controllers')
            ->mapWithKeys(fn (StationRate $rate) => [$rate->controllers => (string) $rate->hourly_rate])
            ->all();
    }

    public function activeSession(): ?GameSession
    {
        return $this->sessions()->where('status', 'active')->first();
    }
}
