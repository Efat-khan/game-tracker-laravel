<?php

namespace App\Http\Controllers;

use App\Http\Requests\PackageRequest;
use App\Http\Resources\Present;
use App\Models\Package;
use App\Models\WalletTransaction;
use App\Services\AuditService;
use App\Support\Money;
use App\Support\Tenancy\CafeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PackageController extends Controller
{
    public function __construct(
        private readonly CafeContext $context,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->context->scope(Package::class);

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        return response()->json($query->orderBy('price')->get()->map(Present::package(...))->all());
    }

    public function store(PackageRequest $request): JsonResponse
    {
        $package = Package::create([
            'cafe_id' => $this->context->id(),
            'name' => $request->string('name')->value(),
            'price' => Money::str($request->input('price')),
            'credit' => Money::str($request->input('credit')),
            'is_active' => $request->boolean('is_active', true),
            'created_at' => now(),
        ]);

        $this->audit->log('package_create', 'package', $package->id, sprintf(
            'Added package %s — pay %s, get %s',
            $package->name,
            Money::str($package->price),
            Money::str($package->credit),
        ));

        return response()->json(Present::package($package), Response::HTTP_CREATED);
    }

    public function update(PackageRequest $request, int $id): JsonResponse
    {
        $package = $this->context->find(Package::class, $id);

        $package->fill($request->safe()->only(['name', 'is_active']));

        foreach (['price', 'credit'] as $field) {
            if ($request->has($field)) {
                $package->{$field} = Money::str($request->input($field));
            }
        }

        $package->save();

        $this->audit->log('package_update', 'package', $package->id, "Updated package {$package->name}");

        return response()->json(Present::package($package));
    }

    /** Retired rather than deleted once it has been sold. */
    public function destroy(int $id): Response
    {
        $package = $this->context->find(Package::class, $id);

        if (WalletTransaction::where('package_id', $package->id)->exists()) {
            $package->is_active = false;
            $package->save();

            $this->audit->log('package_delete', 'package', $package->id, "Retired package {$package->name} (has sales)");
        } else {
            $name = $package->name;
            $package->delete();

            $this->audit->log('package_delete', 'package', $id, "Deleted package {$name}");
        }

        return response()->noContent();
    }
}
