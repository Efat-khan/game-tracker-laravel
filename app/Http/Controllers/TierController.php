<?php

namespace App\Http\Controllers;

use App\Http\Requests\TierRequest;
use App\Http\Resources\Present;
use App\Models\MembershipTier;
use App\Services\AuditService;
use App\Support\Money;
use App\Support\Tenancy\CafeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TierController extends Controller
{
    public function __construct(
        private readonly CafeContext $context,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->context->scope(MembershipTier::class);

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        return response()->json($query->orderBy('min_spend')->get()->map(Present::tier(...))->all());
    }

    public function store(TierRequest $request): JsonResponse
    {
        $tier = MembershipTier::create([
            'cafe_id' => $this->context->id(),
            'name' => $request->string('name')->value(),
            'min_spend' => Money::str($request->input('min_spend', 0)),
            'discount_percent' => Money::str($request->input('discount_percent', 0)),
            'is_active' => $request->boolean('is_active', true),
            'created_at' => now(),
        ]);

        $this->audit->log('tier_create', 'tier', $tier->id, sprintf(
            'Added tier %s at %s spend for %s%% off',
            $tier->name,
            Money::str($tier->min_spend),
            Money::trimPercent($tier->discount_percent),
        ));

        return response()->json(Present::tier($tier), Response::HTTP_CREATED);
    }

    public function update(TierRequest $request, int $id): JsonResponse
    {
        $tier = $this->context->find(MembershipTier::class, $id);

        $tier->fill($request->safe()->only(['name', 'is_active']));

        foreach (['min_spend', 'discount_percent'] as $field) {
            if ($request->has($field)) {
                $tier->{$field} = Money::str($request->input($field));
            }
        }

        $tier->save();

        $this->audit->log('tier_update', 'tier', $tier->id, "Updated tier {$tier->name}");

        return response()->json(Present::tier($tier));
    }

    /**
     * Tiers are reached by computed lifetime spend and are never referenced by
     * a stored row, so a tier can always be deleted outright. Deactivating one
     * is still available through PATCH.
     */
    public function destroy(int $id): Response
    {
        $tier = $this->context->find(MembershipTier::class, $id);

        $name = $tier->name;
        $tier->delete();

        $this->audit->log('tier_delete', 'tier', $id, "Deleted tier {$name}");

        return response()->noContent();
    }
}
