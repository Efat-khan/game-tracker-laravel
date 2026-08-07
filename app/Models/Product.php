<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['cafe_id', 'name', 'category', 'price', 'cost_price', 'is_active'];

    protected $casts = [
        'price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
    ];
}
