<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tenancy\Concerns;

use AlexPavliukov\Authorization\Support\ModelHasRolesQuery;
use AlexPavliukov\Authorization\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Foundation\Auth\User;

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

    /**
     * The owning user by the `owner_key` column. Ownership never grants access
     * by itself — the owner's role row does. Typed as Laravel's base User (what
     * app user models extend) so `$tenant->owner` passes as Authenticatable;
     * `Model&Authenticatable` breaks Larastan's query forwarding (`->first()`).
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        /** @var class-string<User> $userClass */
        $userClass = resolve(ModelHasRolesQuery::class)->userClass();

        return $this->belongsTo($userClass, resolve(CurrentTenant::class)->requireOwnership()[0]);
    }
}
