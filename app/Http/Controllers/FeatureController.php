<?php

namespace App\Http\Controllers;

use App\Models\Cafe;
use App\Services\AuditService;
use App\Services\FeatureService;
use App\Support\Tenancy\CafeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeatureController extends Controller
{
    public function __construct(
        private readonly CafeContext $context,
        private readonly FeatureService $features,
        private readonly AuditService $audit,
    ) {}

    /**
     * What this cafe may use. Readable by admin and staff — the sidebar needs
     * it to know which items to render.
     */
    public function index(): JsonResponse
    {
        return response()->json($this->features->all($this->context->id()));
    }

    /**
     * Superadmin only. This is the grant, so it must never be reachable by a
     * cafe's own admin — otherwise they would simply switch on whatever they
     * were not given.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $cafe = Cafe::find($id);

        if ($cafe === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $rules = [];

        foreach (array_keys(FeatureService::CATALOGUE) as $feature) {
            $rules[$feature] = ['sometimes', 'boolean'];
        }

        $values = $request->validate($rules);

        $before = $this->features->all($cafe->id);
        $after = $this->features->put($cafe->id, $values);

        // Logged against the cafe that was changed, not the owner's own (they
        // have none), so it shows up in that tenant's activity log.
        $changed = [];

        foreach ($after as $feature => $enabled) {
            if (($before[$feature] ?? true) !== $enabled) {
                $changed[] = $feature.' '.($enabled ? 'on' : 'off');
            }
        }

        if ($changed !== []) {
            $this->context->bind($request->user(), $cafe->id);

            $this->audit->log('cafe_features', 'cafe', $cafe->id, sprintf(
                'Features changed — %s',
                implode(', ', $changed),
            ));
        }

        return response()->json($after);
    }
}
