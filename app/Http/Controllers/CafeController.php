<?php

namespace App\Http\Controllers;

use App\Http\Requests\CafeRequest;
use App\Http\Resources\Present;
use App\Models\AdminUser;
use App\Models\Cafe;
use App\Models\Station;
use App\Services\FeatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class CafeController extends Controller
{
    /**
     * An admin or staff member sees their own cafe. The platform owner sees
     * every cafe, with the station and account counts the Cafes screen shows
     * on each card.
     */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isSuperadmin()) {
            $cafe = Cafe::find($user->cafe_id);

            return response()->json($cafe === null ? [] : [Present::cafe($cafe)]);
        }

        $cafes = Cafe::orderBy('id')->get();

        $stationCounts = Station::selectRaw('cafe_id, COUNT(*) as c')->groupBy('cafe_id')->pluck('c', 'cafe_id');
        $accountCounts = AdminUser::whereNotNull('cafe_id')
            ->selectRaw('cafe_id, COUNT(*) as c')->groupBy('cafe_id')->pluck('c', 'cafe_id');

        $features = app(FeatureService::class);

        return response()->json($cafes->map(fn (Cafe $cafe) => Present::cafe(
            $cafe,
            (int) ($stationCounts[$cafe->id] ?? 0),
            (int) ($accountCounts[$cafe->id] ?? 0),
            $features->describe($cafe->id),
        ))->all());
    }

    /** Onboard a cafe together with its first admin. Superadmin only. */
    public function store(CafeRequest $request): JsonResponse
    {
        $cafe = DB::transaction(function () use ($request) {
            $name = $request->string('name')->value();

            $cafe = Cafe::create([
                'name' => $name,
                'slug' => Cafe::uniqueSlug($name),
                'contact_email' => $request->input('contact_email'),
                'is_active' => true,
                'created_at' => now(),
            ]);

            AdminUser::create([
                'cafe_id' => $cafe->id,
                'email' => mb_strtolower(trim($request->string('admin_email')->value())),
                'password_hash' => Hash::make($request->string('admin_password')->value()),
                'role' => 'admin',
                'token_version' => 0,
                'created_at' => now(),
            ]);

            return $cafe;
        });

        return response()->json(Present::cafe($cafe, 0, 1), Response::HTTP_CREATED);
    }

    /** Rename, suspend or reactivate. Superadmin only. */
    public function update(CafeRequest $request, int $id): JsonResponse
    {
        $cafe = Cafe::find($id);

        if ($cafe === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $cafe->fill($request->safe()->only(['name', 'contact_email', 'is_active']));
        $cafe->save();

        return response()->json(Present::cafe($cafe));
    }
}
