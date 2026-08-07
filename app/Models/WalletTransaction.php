<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every change to customers.balance writes one of these rows. The ledger is the
 * audit trail; the balance is a cache of it (§5.3).
 */
class WalletTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'cafe_id', 'customer_id', 'kind', 'amount', 'balance_after',
        'invoice_id', 'package_id', 'note', 'actor_email', 'payment_method', 'shift_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
