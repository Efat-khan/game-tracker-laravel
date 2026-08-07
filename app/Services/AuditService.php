<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Support\Tenancy\CafeContext;

/**
 * §5.6 — append-only activity log. Every money-affecting action lands here with
 * who did it, what they did and a human-readable summary. An anonymous QR
 * check-in is attributed to `customer (QR)` with role `public`.
 */
class AuditService
{
    public function __construct(private readonly CafeContext $context) {}

    public function log(string $action, string $entityType, ?int $entityId, string $summary): AuditEvent
    {
        return AuditEvent::create([
            'cafe_id' => $this->context->id(),
            'actor_id' => $this->context->actorId(),
            'actor_email' => $this->context->actorEmail(),
            'actor_role' => $this->context->actorRole(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'summary' => mb_substr($summary, 0, 300),
        ]);
    }
}
