<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['cafe_id', 'name', 'phone_or_id', 'balance'];

    protected $casts = [
        'balance' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function sessions(): HasMany
    {
        return $this->hasMany(GameSession::class, 'customer_id');
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
