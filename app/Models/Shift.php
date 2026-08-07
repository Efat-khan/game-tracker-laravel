<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'cafe_id', 'opened_by_id', 'opened_by_email', 'opened_at', 'opening_float',
        'open_note', 'closed_by_id', 'closed_by_email', 'closed_at', 'counted_cash',
        'expected_cash', 'variance', 'close_note', 'status',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_float' => 'decimal:2',
        'counted_cash' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'variance' => 'decimal:2',
    ];

    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }
}
