<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Support\Tenancy\CafeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin only — staff cannot read the activity log (§3). */
class AuditController extends Controller
{
    public function __construct(private readonly CafeContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->context->scope(AuditEvent::class);

        if ($request->filled('action')) {
            $query->where('action', $request->string('action')->value());
        }

        $limit = min(1000, max(1, (int) $request->query('limit', 200)));

        $events = $query->orderByDesc('id')->limit($limit)->get();

        return response()->json($events->map(fn (AuditEvent $e) => [
            'id' => $e->id,
            'actor_email' => $e->actor_email,
            'actor_role' => $e->actor_role,
            'action' => $e->action,
            'entity_type' => $e->entity_type,
            'entity_id' => $e->entity_id,
            'summary' => $e->summary,
            'created_at' => $e->created_at?->utc()->format('Y-m-d\TH:i:s'),
        ])->all());
    }
}
