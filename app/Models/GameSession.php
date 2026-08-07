<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A play session. Named GameSession rather than Session so it never reads as
 * the framework's HTTP session; the table is still `sessions`.
 */
class GameSession extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'sessions';

    protected $fillable = [
        'cafe_id', 'station_id', 'customer_id', 'start_time', 'end_time', 'status',
        'hourly_rate_snapshot', 'controllers', 'base_rate_snapshot',
        'extra_controller_rate_snapshot', 'planned_minutes',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'hourly_rate_snapshot' => 'decimal:2',
        'base_rate_snapshot' => 'decimal:2',
        'extra_controller_rate_snapshot' => 'decimal:2',
        'controllers' => 'integer',
        'planned_minutes' => 'integer',
        'created_at' => 'datetime',
    ];

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'session_id');
    }
}
