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

            $rolePermissions = $role->permissions();

            if ($rolePermissions !== []) {
                $spatieRole->syncPermissions($rolePermissions);
            }
        }
    }

    private function guardName(): string
    {
        $guard = config('auth.defaults.guard');

        return is_string($guard) ? $guard : 'web';
    }
}
