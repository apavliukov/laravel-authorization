<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Fail-closed tenant filter: no current tenant and no bypass ⇒ no rows
 * (and an exception in strict mode). Never "no tenant ⇒ no filter".
 *
 * @implements Scope<Model>
 */
final readonly class TenantScope implements Scope
{
    /** @param string|null $relationPath dotted path to a BelongsToTenant model, for transitively owned models */
    public function __construct(private ?string $relationPath = null) {}

    /** @param Builder<covariant Model> $builder */
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = resolve(CurrentTenant::class);

        if ($tenant->isBypassed()) {
            return;
        }

        $id = $tenant->id();

        if ($id === null) {
            if (self::strict()) {
                throw MissingTenantContextException::forModel($model::class);
            }

            $builder->whereRaw('1 = 0');

            return;
        }

        if ($this->relationPath !== null) {
            $builder->whereHas($this->relationPath);

            return;
        }

        $builder->where($model->qualifyColumn($tenant->foreignKey()), $id);
    }

    public static function strict(): bool
    {
        return config('authorization.tenancy.strict') === true;
    }
}
