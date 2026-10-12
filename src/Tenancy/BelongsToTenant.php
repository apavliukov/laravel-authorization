<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use LogicException;

/**
 * Tenant-owned model: filtered by TenantScope, tenant key autofilled on create
 * (creating with no tenant and no explicit key always throws — a bypass never
 * produces orphans).
 * Route binding 404s across tenants because the scope applies to the lookup.
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

            $id = $tenant->id();

            if ($id === null) {
                throw MissingTenantContextException::forModel($model::class);
            }

            $model->setAttribute($foreignKey, $id);
        });
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
