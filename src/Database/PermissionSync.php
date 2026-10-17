<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Database;

use AlexPavliukov\Authorization\AuthorizationManager;
use AlexPavliukov\Authorization\PermissionRegistry;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use LogicException;
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
     * Create missing permissions, then set each role's permissions to exactly its
     * `permissions()` declaration (an empty declaration detaches all — deny-all).
     * Permissions the registry no longer declares are left in place.
     */
    public function apply(): void
    {
        $this->assertPriorityIsComplete();
        $this->permissions();
        $this->roles();
    }

    /**
     * byPriority() must list every role case exactly once. Checked here, on the
     * deploy path, rather than at boot, which stays config-only.
     */
    private function assertPriorityIsComplete(): void
    {
        $roleEnum = $this->manager->roleEnum();
        $declared = array_map(static fn (BackedEnum $role): string => (string) $role->value, $roleEnum::cases());
        $ranked = array_map(static fn (BackedEnum $role): string => (string) $role->value, $roleEnum::byPriority());

        $missing = array_diff($declared, $ranked);
        $duplicated = array_keys(array_filter(array_count_values($ranked), static fn (int $count): bool => $count > 1));

        if ($missing === [] && $duplicated === []) {
            return;
        }

        throw new LogicException(sprintf(
            '%s::byPriority() must list every case exactly once. Missing: [%s]. Duplicated: [%s].',
            $roleEnum,
            implode(', ', $missing),
            implode(', ', $duplicated),
        ));
    }

    private function guardName(): string
    {
        $guard = config('auth.defaults.guard');

        return is_string($guard) ? $guard : 'web';
    }
}
