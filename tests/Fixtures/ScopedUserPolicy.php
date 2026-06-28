<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Fixtures;

use AlexPavliukov\Authorization\AbstractPolicy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Exercises the ownsModel() hook: a user only "owns" their own record. Stands in
 * for a real tenancy-scoped policy (e.g. company_id / team_id match).
 */
final readonly class ScopedUserPolicy extends AbstractPolicy
{
    protected function getModelClass(): string
    {
        return User::class;
    }

    protected function ownsModel(Authenticatable $user, Model $model): bool
    {
        return $user->getAuthIdentifier() === $model->getKey();
    }
}
