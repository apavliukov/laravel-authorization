<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use LogicException;

/**
 * Fail-closed tenant filter: no current tenant and no bypass ⇒ no rows
 * (and an exception in strict mode). Never "no tenant ⇒ no filter".
 * It fences by tenant only: on a relation path no other global scope of a hop
 * (app scopes, soft deletes) leaks into it.
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
            self::constrainThrough($builder, explode('.', $this->relationPath), $model::class);

            return;
        }

        $builder->where($model->qualifyColumn($tenant->foreignKey()), $id);
    }

    /**
     * One whereHas per hop with every global scope dropped; the last hop keeps only
     * its own TenantScope, which compares the column or walks its own path.
     *
     * @param  Builder<covariant Model>  $builder
     * @param  list<string>  $segments
     * @param  class-string<Model>  $owner
     */
    private static function constrainThrough(Builder $builder, array $segments, string $owner): void
    {
        $relation = array_shift($segments);

        $builder->whereHas((string) $relation, static function (Builder $hop) use ($segments, $owner): void {
            $model = $hop->getModel();
            $hop->withoutGlobalScopes();

            if ($segments !== []) {
                self::constrainThrough($hop, $segments, $owner);

                return;
            }

            $fence = $model::getGlobalScope(self::class);

            if (! $fence instanceof self) {
                throw new LogicException("{$owner}'s tenant relation path ends at ".$model::class.', which does not use BelongsToTenant.');
            }

            $hop->withGlobalScope(self::class, $fence);
        });
    }

    public static function strict(): bool
    {
        return config('authorization.tenancy.strict') === true;
    }
}
