<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'cafe_id', 'session_id', 'total_amount', 'session_amount', 'items_amount',
        'discount_amount', 'discount_reason', 'duration_minutes', 'payment_method',
        'payment_status', 'status', 'void_reason', 'paid_at', 'shift_id',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'session_amount' => 'decimal:2',
        'items_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'duration_minutes' => 'integer',
        'paid_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(GameSession::class, 'session_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    /** A void invoice is excluded from all revenue, analytics and lifetime spend. */
    public function scopeNotVoid($query)
    {
        return $query->where('status', '!=', 'void');
    }
}
