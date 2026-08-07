<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MembershipTier extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['cafe_id', 'name', 'min_spend', 'discount_percent', 'is_active'];

    protected $casts = [
        'min_spend' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
    ];
}
