<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Testing;

use AlexPavliukov\Authorization\AuthorizationManager;
use AlexPavliukov\Authorization\Support\ModelHasRolesQuery;
use BackedEnum;
use Illuminate\Contracts\Auth\Authenticatable;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * Test helpers for arranging team-aware authorization state. Mix into a test case
 * to assign global / team-scoped roles and drive the active permissions team
 * without re-implementing the Spatie plumbing in every project.
 */
trait InteractsWithAuthorization
{
    /** Resolve a role enum case (or name) to its Spatie roles-table id. */
    protected function roleModelId(BackedEnum|string $role): int
    {
        /** @var class-string<Role> $roleClass */
        $roleClass = config('permission.models.role');

        $name = ModelHasRolesQuery::roleName($role);

        $id = $roleClass::query()
            ->where('name', $name)
            ->where('guard_name', $this->authorizationGuard())
            ->value('id');

        return is_numeric($id)
            ? (int) $id
            : throw new RuntimeException("Authorization role [{$name}] not found.");
    }

    /**
     * Assign a role to a user within a specific team, or globally when $teamId is
     * null, restoring the previously active team afterwards.
     */
    protected function assignRoleInTeam(Authenticatable $user, BackedEnum|string $role, int|string|null $teamId): void
    {
        $this->withPermissionsTeam($teamId, static function () use ($user, $role): void {
            /** @phpstan-ignore method.notFound (the package requires the user to use Spatie\Permission\Traits\HasRoles) */
            $user->assignRole(ModelHasRolesQuery::roleName($role));
        });
    }

    /** Run a callback under a temporary permissions team, restoring the previous one. */
    protected function withPermissionsTeam(int|string|null $teamId, callable $callback): mixed
    {
        return resolve(AuthorizationManager::class)->withTeam($teamId, $callback);
    }

    /** Clear the active permissions team. */
    protected function resetPermissionsTeam(): void
    {
        setPermissionsTeamId(null);
    }

    private function authorizationGuard(): string
    {
        $guard = config('auth.defaults.guard');

        return is_string($guard) ? $guard : 'web';
    }
}
