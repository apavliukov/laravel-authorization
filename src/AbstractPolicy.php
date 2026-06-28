<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization;

use AlexPavliukov\Authorization\Enums\Ability;
use BackedEnum;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

abstract readonly class AbstractPolicy
{
    protected string $modelClass;

    public function __construct(private PermissionRegistry $registry)
    {
        $this->modelClass = $this->getModelClass();
    }

    /** @return class-string<Model> */
    abstract protected function getModelClass(): string;

    /** @param Authenticatable&Authorizable $user */
    public function viewAny(Authenticatable $user): bool
    {
        return $this->userCan($user, Ability::VIEW_ANY);
    }

    /** @param Authenticatable&Authorizable $user */
    public function view(Authenticatable $user, Model $model): bool
    {
        return $this->ownsModel($user, $model) && $this->userCan($user, Ability::VIEW, $model);
    }

    /** @param Authenticatable&Authorizable $user */
    public function create(Authenticatable $user): bool
    {
        return $this->userCan($user, Ability::CREATE);
    }

    /** @param Authenticatable&Authorizable $user */
    public function update(Authenticatable $user, Model $model): bool
    {
        return $this->ownsModel($user, $model) && $this->userCan($user, Ability::UPDATE, $model);
    }

    /** @param Authenticatable&Authorizable $user */
    public function delete(Authenticatable $user, Model $model): bool
    {
        return $this->ownsModel($user, $model) && $this->userCan($user, Ability::DELETE, $model);
    }

    /** @param Authenticatable&Authorizable $user */
    public function restore(Authenticatable $user, Model $model): bool
    {
        return $this->ownsModel($user, $model) && $this->userCan($user, Ability::RESTORE, $model);
    }

    /** @param Authenticatable&Authorizable $user */
    public function forceDelete(Authenticatable $user, Model $model): bool
    {
        return $this->ownsModel($user, $model) && $this->userCan($user, Ability::FORCE_DELETE, $model);
    }

    /**
     * Tenancy / ownership hook for model-bound checks. The default grants access
     * (no fencing); override to scope a model to the user (e.g. company_id /
     * team_id match, or a relation walk). Model-less checks (viewAny, create) do
     * not consult it.
     */
    protected function ownsModel(Authenticatable $user, Model $model): bool
    {
        return true;
    }

    /** @param Authenticatable&Authorizable $user */
    protected function userCan(Authenticatable $user, BackedEnum $ability, Model|string|null $model = null): bool
    {
        $modelToCheck = $model ?? $this->modelClass;

        return $user->can($this->registry->nameFromAbility($ability, $modelToCheck));
    }
}
