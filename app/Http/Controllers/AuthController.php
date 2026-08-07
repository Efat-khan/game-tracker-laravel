<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\Cafe;
use App\Services\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(private readonly TokenService $tokens) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'min:3', 'max:150'],
            'password' => ['required', 'string'],
        ]);

        // Login emails are unique platform-wide, so a sign-in is never
        // ambiguous about which tenant it belongs to (§4.5).
        $user = AdminUser::whereRaw('LOWER(email) = ?', [mb_strtolower(trim($data['email']))])->first();

        if ($user === null || ! Hash::check($data['password'], $user->password_hash)) {
            return response()->json(['message' => 'Wrong email or password.'], 401);
        }

        $cafe = $user->cafe_id !== null ? Cafe::find($user->cafe_id) : null;

        if ($cafe !== null && ! $cafe->is_active) {
            return response()->json(['message' => 'This cafe is suspended.'], 403);
        }

        return response()->json([
            'access_token' => $this->tokens->issue($user),
            'email' => $user->email,
            'role' => $user->role,
            'cafe_id' => $user->cafe_id,
            'cafe_name' => $cafe?->name,
        ]);
    }
}
