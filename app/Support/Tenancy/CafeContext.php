<?php

namespace App\Support\Tenancy;

use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The one place that knows which cafe the current request is acting on.
 *
 * Resolved once per request by ResolveCafeContext and then used by every query
 * in the app. The rules it enforces (§4):
 *
 *   - An admin/staff token is bound to its own cafe. Nothing the client sends
 *     can widen that.
 *   - A superadmin belongs to no cafe and names one per request with X-Cafe-Id.
 *     Without it: 400, never a silent default to cafe 1.
 *   - A by-id lookup for another tenant's record returns 404, not 403 — a 403
 *     would leak the fact that the record exists.
 */
class CafeContext
{
    private ?int $cafeId = null;

    private ?AdminUser $actor = null;

    public function bind(?AdminUser $actor, ?int $cafeId): void
    {
        $this->actor = $actor;
        $this->cafeId = $cafeId;
    }

    /** The active cafe. Aborts 400 when a superadmin has not named one. */
    public function id(): int
    {
        if ($this->cafeId === null) {
            throw new HttpException(400, 'A cafe must be selected. Send an X-Cafe-Id header.');
        }

        return $this->cafeId;
    }

    public function idOrNull(): ?int
    {
        return $this->cafeId;
    }

    public function actor(): ?AdminUser
    {
        return $this->actor;
    }

    /** An anonymous QR check-in has no account behind it (§5.6). */
    public function actorEmail(): string
    {
        return $this->actor?->email ?? 'customer (QR)';
    }

    public function actorRole(): string
    {
        return $this->actor?->role ?? 'public';
    }

    public function actorId(): ?int
    {
        return $this->actor?->id;
    }

    public function isAdmin(): bool
    {
        return (bool) $this->actor?->isAdmin();
    }

    /**
     * Start a query already narrowed to this cafe. Use this instead of
     * Model::query() for every operational table.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function scope(string $modelClass): Builder
    {
        return $modelClass::query()->where($modelClass::query()->getModel()->getTable().'.cafe_id', $this->id());
    }

    /**
     * "Find by id within this cafe, else 404." The helper the spec asks for —
     * every by-id route goes through here so no route can accidentally answer
     * 403 and confirm that another tenant's record exists.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $modelClass
     * @return T
     */
    public function find(string $modelClass, int|string $id): Model
    {
        $record = $this->scope($modelClass)->whereKey($id)->first();

        if ($record === null) {
            throw new NotFoundHttpException('Not found.');
        }

        return $record;
    }
}
