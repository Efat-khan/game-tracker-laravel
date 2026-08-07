<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Station extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'cafe_id', 'name', 'type', 'hourly_rate', 'extra_controller_rate',
        'max_controllers', 'qr_code_url', 'is_active', 'maintenance',
    ];

    protected $casts = [
        'hourly_rate' => 'decimal:2',
        'extra_controller_rate' => 'decimal:2',
        'max_controllers' => 'integer',
        'is_active' => 'boolean',
        'maintenance' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function sessions(): HasMany
    {
        return $this->hasMany(GameSession::class, 'station_id');
    }

    public function activeSession(): ?GameSession
    {
        return $this->sessions()->where('status', 'active')->first();
    }
}
