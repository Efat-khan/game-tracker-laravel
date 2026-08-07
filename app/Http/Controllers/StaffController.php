<?php

namespace App\Http\Controllers;

use App\Http\Requests\StaffRequest;
use App\Models\AdminUser;
use App\Services\AuditService;
use App\Support\Tenancy\CafeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/** Admin only, throughout. */
class StaffController extends Controller
{
    public function __construct(
        private readonly CafeContext $context,
        private readonly AuditService $audit,
    ) {}

    private function present(AdminUser $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'cafe_id' => $user->cafe_id,
            'created_at' => $user->created_at?->utc()->format('Y-m-d\TH:i:s'),
        ];
    }

    /**
     * A superadmin belongs to no cafe and must never appear in any cafe's staff
     * list — scoping on cafe_id is what keeps them out (§4.4).
     */
    public function index(): JsonResponse
    {
        $users = $this->context->scope(AdminUser::class)->orderBy('id')->get();

        return response()->json($users->map($this->present(...))->all());
    }

    public function store(StaffRequest $request): JsonResponse
    {
        $user = AdminUser::create([
            'cafe_id' => $this->context->id(),
            'email' => mb_strtolower(trim($request->string('email')->value())),
            'password_hash' => Hash::make($request->string('password')->value()),
            'role' => $request->string('role')->value(),
            'token_version' => 0,
            'created_at' => now(),
        ]);

        $this->audit->log('staff_create', 'staff', $user->id, "Created {$user->role} account {$user->email}");

        return response()->json($this->present($user), Response::HTTP_CREATED);
    }

    /**
     * Role change or password reset. Either one bumps token_version, which
     * invalidates every token that user is holding (§7.1).
     */
    public function update(StaffRequest $request, int $id): JsonResponse
    {
        $user = $this->context->find(AdminUser::class, $id);

        $changes = [];

        if ($request->filled('role') && $request->string('role')->value() !== $user->role) {
            if ($user->role === 'admin' && $request->string('role')->value() !== 'admin' && $this->isLastAdmin($user)) {
                return response()->json(['message' => 'The last admin cannot be demoted.'], 400);
            }

            $user->role = $request->string('role')->value();
            $changes[] = "role to {$user->role}";
        }

        if ($request->filled('password')) {
            $user->password_hash = Hash::make($request->string('password')->value());
            $changes[] = 'password reset';
        }

        if ($request->filled('email')) {
            $user->email = mb_strtolower(trim($request->string('email')->value()));
            $changes[] = "email to {$user->email}";
        }

        if ($changes !== []) {
            $user->token_version = $user->token_version + 1;
        }

        $user->save();

        $this->audit->log('staff_update', 'staff', $user->id, sprintf(
            'Updated %s — %s',
            $user->email,
            $changes === [] ? 'no change' : implode(', ', $changes),
        ));

        return response()->json($this->present($user));
    }

    /** Sign a user out of every device they are holding a token on. */
    public function revoke(int $id): JsonResponse
    {
        $user = $this->context->find(AdminUser::class, $id);

        $user->token_version = $user->token_version + 1;
        $user->save();

        $this->audit->log('staff_revoke', 'staff', $user->id, "Signed {$user->email} out of all devices");

        return response()->json($this->present($user));
    }

    public function destroy(int $id): Response
    {
        $user = $this->context->find(AdminUser::class, $id);

        if ($user->role === 'admin' && $this->isLastAdmin($user)) {
            return response()->json(['message' => 'The last admin cannot be deleted.'], 400);
        }

        $email = $user->email;
        $user->delete();

        $this->audit->log('staff_delete', 'staff', $id, "Deleted account {$email}");

        return response()->noContent();
    }

    /**
     * A cafe with no admin left is a cafe nobody can administer — the platform
     * owner would have to step in to fix it.
     */
    private function isLastAdmin(AdminUser $user): bool
    {
        return ! AdminUser::where('cafe_id', $user->cafe_id)
            ->where('role', 'admin')
            ->whereKeyNot($user->id)
            ->exists();
    }
}
