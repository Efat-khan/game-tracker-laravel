<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only. Never updated, never deleted (§5.6). */
class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'cafe_id', 'actor_id', 'actor_email', 'actor_role',
        'action', 'entity_type', 'entity_id', 'summary',
    ];

    protected $casts = [
        'entity_id' => 'integer',
        'created_at' => 'datetime',
    ];
}
