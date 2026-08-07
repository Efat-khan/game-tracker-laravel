<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A top-up deal: pay `price`, receive `credit`. The bonus drives upfront cash. */
class Package extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['cafe_id', 'name', 'price', 'credit', 'is_active'];

    protected $casts = [
        'price' => 'decimal:2',
        'credit' => 'decimal:2',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
    ];
}
