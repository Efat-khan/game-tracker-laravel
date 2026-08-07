<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

class AdminUser extends Authenticatable
{
    public const UPDATED_AT = null;

    protected $table = 'admin_users';

    /**
     * cafe_id is deliberately absent from any default: a superadmin has no cafe
     * and must never be silently filed into cafe 1 (§4.4).
     */
    protected $fillable = ['cafe_id', 'email', 'password_hash', 'role', 'token_version'];

    protected $hidden = ['password_hash'];

    protected $casts = [
        'cafe_id' => 'integer',
        'token_version' => 'integer',
        'created_at' => 'datetime',
    ];

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function cafe(): BelongsTo
    {
        return $this->belongsTo(Cafe::class);
    }

    public function isSuperadmin(): bool
    {
        return $this->role === 'superadmin';
    }

    /**
     * A superadmin passes every admin check once they have selected a cafe —
     * otherwise the platform owner could open a tenant but not fix anything
     * in it (§3).
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin' || $this->isSuperadmin();
    }
}
