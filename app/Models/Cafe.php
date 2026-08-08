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
     *
     * `$ignoreId` is the cafe being renamed. Without it, re-saving a cafe under
     * a name it already has would collide with its own row and walk the slug on
     * to `-2` for no reason.
     */
    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = substr(str($name)->slug()->value() ?: 'cafe', 0, 55);
        $slug = $base;
        $n = 2;

        while (static::where('slug', $slug)->whereKeyNot($ignoreId)->exists()) {
            $slug = $base.'-'.$n;
            $n++;
        }

        return $slug;
    }
}
