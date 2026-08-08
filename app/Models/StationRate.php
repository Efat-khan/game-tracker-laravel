<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One hourly rate for one controller count on one station.
 *
 * Composite key, no timestamps: a rate row is a cell in a price list, not an
 * entity with a history. Changing a price rewrites the row — sessions already
 * snapshot the rate they were started at, so nothing that has been billed can
 * move underneath it.
 */
class StationRate extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $fillable = ['station_id', 'controllers', 'hourly_rate'];

    protected $casts = [
        'controllers' => 'integer',
        'hourly_rate' => 'decimal:2',
    ];

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }
}
