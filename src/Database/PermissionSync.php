<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Database;

use AlexPavliukov\Authorization\AuthorizationManager;
use AlexPavliukov\Authorization\PermissionRegistry;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final readonly class PermissionSync
{
    public function __construct(
        private PermissionRegistry $registry,
        private AuthorizationManager $manager,
    ) {}

    public function permissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var class-string<Model> $permissionClass */
        $permissionClass = config('permission.models.permission');

        foreach ($this->registry->allPermissions() as $permissionName) {
            $permissionClass::query()->firstOrCreate([
                'name' => $permissionName,
                'guard_name' => $this->guardName(),
            ]);
        }
    }

    public function roles(): void
    {
        /** @var class-string<Role> $roleClass */
        $roleClass = config('permission.models.role');
        $roleEnum = $this->manager->roleEnum();

        foreach ($roleEnum::cases() as $role) {
            $spatieRole = $roleClass::query()->firstOrCreate([
                'name' => $role->value,
                'guard_name' => $this->guardName(),
            ]);

            // syncPermissions is authoritative: an empty set detaches every
            // permission, which is exactly what a deny-all role declares.
            $spatieRole->syncPermissions($role->permissions());
        }
    }

    /**
     * Diff the declared authorization (registry permissions + each role enum
     * case's permissions()) against what is currently stored — without mutating
     * anything. Intended for preview / dry-run.
     *
     * @return array{
     *   permissions: array{create: list<string>, remove: list<string>},
     *   roles: array<string, array{grant: list<string>, revoke: list<string>}>,
     * }
     */
    public function plan(): array
    {
        [$create, $remove] = $this->diff($this->desiredPermissionNames(), $this->existingPermissionNames());

        return [
            'permissions' => ['create' => $create, 'remove' => $remove],
            'roles' => $this->roleDiffs(),
        ];
    }

    /**
     * Create missing permissions, optionally prune permissions no longer declared
     * by the registry, then set each role's permissions to exactly its
     * `permissions()` declaration (an empty declaration detaches all — deny-all).
     * Pruning is opt-in: enable it only when permissions are managed solely through
     * this package — it deletes every permission under the guard the registry no
     * longer declares.
     */
    public function apply(bool $prune = false): void
    {
        $this->permissions();

        if ($prune) {
            $this->prunePermissions();
        }

        $this->roles();
    }

    private function prunePermissions(): void
    {
        /** @var class-string<Model> $permissionClass */
        $permissionClass = config('permission.models.permission');

        $orphans = array_values(array_diff($this->existingPermissionNames(), $this->desiredPermissionNames()));

        if ($orphans === []) {
            return;
        }

        $permissionClass::query()
            ->where('guard_name', $this->guardName())
            ->whereIn('name', $orphans)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return list<string> */
    private function desiredPermissionNames(): array
    {
        return array_values(array_unique($this->registry->allPermissions()));
    }

    /** @return list<string> */
    private function existingPermissionNames(): array
    {
        /** @var class-string<Model> $permissionClass */
        $permissionClass = config('permission.models.permission');

        /** @var list<string> $names */
        $names = $permissionClass::query()
            ->where('guard_name', $this->guardName())
            ->pluck('name')
            ->all();

        return $names;
    }

    /** @return array<string, array{grant: list<string>, revoke: list<string>}> */
    private function roleDiffs(): array
    {
        /** @var class-string<Role> $roleClass */
        $roleClass = config('permission.models.role');
        $roleEnum = $this->manager->roleEnum();
        $guard = $this->guardName();

        $diffs = [];

        foreach ($roleEnum::cases() as $role) {
            /** @var list<string> $desired */
            $desired = array_values(array_unique(array_filter($role->permissions(), 'is_string')));

            $spatieRole = $roleClass::query()
                ->where('name', $role->value)
                ->where('guard_name', $guard)
                ->first();

            $existing = $spatieRole instanceof Role ? $this->rolePermissionNames($spatieRole) : [];

            [$grant, $revoke] = $this->diff($desired, $existing);

            $diffs[(string) $role->value] = ['grant' => $grant, 'revoke' => $revoke];
        }

        return $diffs;
    }

    /** @return list<string> */
    private function rolePermissionNames(Role $role): array
    {
        /** @var list<string> $names */
        $names = $role->permissions->pluck('name')->all();

        return $names;
    }

    /**
     * @param  list<string>  $desired
     * @param  list<string>  $existing
     * @return array{0: list<string>, 1: list<string>}
     */
    private function diff(array $desired, array $existing): array
    {
        return [
            array_values(array_diff($desired, $existing)),
            array_values(array_diff($existing, $desired)),
        ];
    }

    private function guardName(): string
    {
        $guard = config('auth.defaults.guard');

        return is_string($guard) ? $guard : 'web';
    }
}
