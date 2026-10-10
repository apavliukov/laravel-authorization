<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tenancy\Concerns;

use AlexPavliukov\Authorization\Support\ModelHasRolesQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * For the tenant model: its members are the users holding any role in its team,
 * read straight from `model_has_roles`. Filter by role on the user side
 * (`->whereHasRoleInTeam(Role::OWNER, $this->getKey())`). A member with several
 * roles is one row, but `count()` ignores the DISTINCT — use `exists()` or
 * `count('users.id')`.
 */
trait HasMembers
{
    /** @return MorphToMany<Model, Model> */
    public function members(): MorphToMany
    {
        return resolve(ModelHasRolesQuery::class)->membersOf($this);
    }
}
