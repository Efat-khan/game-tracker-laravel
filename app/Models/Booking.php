<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'cafe_id', 'station_id', 'customer_name', 'customer_phone', 'starts_at',
        'ends_at', 'controllers', 'status', 'note', 'session_id', 'created_by_email',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'controllers' => 'integer',
        'created_at' => 'datetime',
    ];

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }
}
