<?php

namespace App\Services;

use App\Models\AdminUser;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

/**
 * §7.1 — HS256 bearer tokens.
 *
 * Claims: sub, email, role, cafe (nullable), tv (token version), exp (12h).
 *
 * `tv` is the sign-out-everywhere mechanism: admin_users.token_version is
 * bumped whenever a password or role changes or an admin forces a sign-out,
 * and every request compares the claim against the stored value. Keeping the
 * claim set identical to the FastAPI reference means both backends can mint
 * tokens the other accepts during a migration.
 */
class TokenService
{
    public const ALGO = 'HS256';

    public function secret(): string
    {
        $secret = (string) config('cafetrack.jwt_secret');

        if ($secret === '') {
            throw new \RuntimeException('No JWT signing key: set JWT_SECRET or APP_KEY.');
        }

        return $secret;
    }

    public function issue(AdminUser $user): string
    {
        $now = time();

        return JWT::encode([
            'sub' => (string) $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'cafe' => $user->cafe_id,
            'tv' => (int) $user->token_version,
            'iat' => $now,
            'exp' => $now + (config('cafetrack.jwt_ttl_hours') * 3600),
        ], $this->secret(), self::ALGO);
    }

    /** @return array<string,mixed>|null null on anything malformed, expired or mis-signed */
    public function parse(string $token): ?array
    {
        try {
            return (array) JWT::decode($token, new Key($this->secret(), self::ALGO));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resolve a token to its user, rejecting one whose version claim has been
     * left behind by a password change, role change or forced sign-out.
     */
    public function resolve(string $token): ?AdminUser
    {
        $claims = $this->parse($token);

        if ($claims === null || ! isset($claims['sub'])) {
            return null;
        }

        $user = AdminUser::find((int) $claims['sub']);

        if ($user === null) {
            return null;
        }

        if ((int) ($claims['tv'] ?? -1) !== (int) $user->token_version) {
            return null;
        }

        return $user;
    }
}
