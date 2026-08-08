<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    public const UPDATED_AT = null;

    /**
     * A fixed catalogue rather than free text.
     *
     * Free text turns into "Electricity", "electric bill" and "ELEC" inside a
     * month, and a per-category total that adds up to nothing. `Other` carries
     * the long tail, and the note says what it actually was.
     */
    public const CATEGORIES = [
        'stock' => 'Stock & supplies',
        'utilities' => 'Utilities',
        'internet' => 'Internet',
        'rent' => 'Rent',
        'salary' => 'Salary',
        'maintenance' => 'Repairs & maintenance',
        'equipment' => 'Equipment',
        'transport' => 'Transport',
        'marketing' => 'Marketing',
        'other' => 'Other',
    ];

    /** How it was paid. Only `cash` touches the drawer. */
    public const METHODS = ['cash', 'phone_payment', 'bank', 'other'];

    protected $fillable = [
        'cafe_id', 'shift_id', 'cash_movement_id', 'category',
        'amount', 'payment_method', 'note', 'spent_on', 'actor_email',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'spent_on' => 'date',
        'created_at' => 'datetime',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function cashMovement(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }
}
