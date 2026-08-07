<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cafe extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['name', 'slug', 'contact_email', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function admins(): HasMany
    {
        return $this->hasMany(AdminUser::class);
    }

    public function stations(): HasMany
    {
        return $this->hasMany(Station::class);
    }

    /**
     * Turn a cafe name into a unique slug, appending -2, -3 … on collision.
     */
    public static function uniqueSlug(string $name): string
    {
        $base = substr(str($name)->slug()->value() ?: 'cafe', 0, 55);
        $slug = $base;
        $n = 2;

        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n;
            $n++;
        }

        return $slug;
    }
}
