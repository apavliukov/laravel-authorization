<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use LogicException;

/**
 * Tenant-owned model: filtered by TenantScope, tenant key autofilled on create
 * (creating with no tenant and no explicit key throws — a bypass never produces
 * orphans, unless the model opts in via allowsNullTenant()).
 * Route binding 404s across tenants because the scope applies to the lookup;
 * so does restoration (Livewire model props, SerializesModels jobs).
 * Models that reach the tenant through another model override
 * tenantRelationPath() (e.g. 'post' or 'campaign.position') instead of
 * carrying the column themselves.
 */
trait BelongsToTenant
{
    /** Dotted relation path to a BelongsToTenant model, for transitively owned models. */
    protected static function tenantRelationPath(): ?string
    {
        return null;
    }

    /**
     * Rows with a NULL tenant are part of the model (e.g. platform-wide templates):
     * created under an explicit bypass they keep NULL — even if a tenant is active
     * (a super-admin's session choice in the platform area). Inside a tenant the key
     * is still autofilled; NULL rows are visible only under bypass.
     */
    protected static function allowsNullTenant(): bool
    {
        return false;
    }

    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope(static::tenantRelationPath()));

        static::creating(static function (Model $model): void {
            if (static::tenantRelationPath() !== null) {
                return;
            }

            $tenant = resolve(CurrentTenant::class);
            $foreignKey = $tenant->foreignKey();

            if ($model->getAttribute($foreignKey) !== null) {
                return;
            }

            if ($tenant->isBypassed() && static::allowsNullTenant()) {
                return;
            }

            $id = $tenant->id();

            if ($id === null) {
                throw MissingTenantContextException::forModel($model::class);
            }

            $model->setAttribute($foreignKey, $id);
        });
    }

    /**
     * Eloquent restores without any global scope; keep TenantScope (and only it,
     * so e.g. a soft-deleted model still restores) — a replayed Livewire snapshot
     * or a job must not load another tenant's row.
     *
     * @param  array<int, mixed>|int  $ids
     * @return Builder<static>
     */
    public function newQueryForRestoration($ids)
    {
        /** @phpstan-ignore return.type (larastan narrows the trait's Builder<static> to the concrete class) */
        return $this->newQueryWithoutScopes()
            ->withGlobalScope(TenantScope::class, new TenantScope(static::tenantRelationPath()))
            ->whereKey($ids);
    }

    /**
     * The owning tenant: a BelongsTo on column models, a HasOneThrough on a
     * one-hop path (e.g. 'post'), whose keys come from the hop's own relations.
     * Longer paths throw — walk the relation instead.
     *
     * @return BelongsTo<Model, $this>|HasOneThrough<Model, Model, $this>
     */
    public function tenant(): BelongsTo|HasOneThrough
    {
        $path = static::tenantRelationPath();

        if ($path === null) {
            $current = resolve(CurrentTenant::class);

            return $this->belongsTo($current->modelClass(), $current->foreignKey());
        }

        $hop = str_contains($path, '.') ? null : $this->{$path}();
        $inner = $hop instanceof BelongsTo && method_exists($hop->getRelated(), 'tenant') ? $hop->getRelated()->tenant() : null;

        if (! $hop instanceof BelongsTo || ! $inner instanceof BelongsTo) {
            throw new LogicException(static::class.' reaches its tenant through ['.$path.']; walk that relation instead of tenant().');
        }

        /** @var Model $through */
        $through = $hop->getRelated();

        return $this->hasOneThrough(
            $inner->getRelated()::class,
            $through::class,
            $hop->getOwnerKeyName(),
            $inner->getOwnerKeyName(),
            $hop->getForeignKeyName(),
            $inner->getForeignKeyName(),
        );
    }
}
