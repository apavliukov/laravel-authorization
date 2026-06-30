<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final readonly class PermissionRegistry
{
    public function __construct(private AuthorizationManager $manager) {}

    public function nameFromAbility(BackedEnum $ability, Model|string $model): string
    {
        $table = $model instanceof Model ? $model->getTable() : $this->tableFor($model);

        return sprintf('%s %s', Str::snake((string) $ability->value, ' '), str_replace('_', ' ', $table));
    }

    /** @return array<int, string> */
    public function allPermissions(): array
    {
        $permissions = [];

        foreach ($this->manager->models() as $modelClass) {
            foreach ($this->permissionsFor($modelClass) as $permission) {
                $permissions[] = $permission;
            }
        }

        return $permissions;
    }

    /**
     * @param  class-string  $modelClass
     * @return array<int, string>
     */
    public function permissionsFor(string $modelClass): array
    {
        $abilities = array_merge(
            $modelClass::getBasicAbilities(),
            $modelClass::getCustomAbilities(),
        );

        return array_map(
            fn (BackedEnum $ability): string => $this->nameFromAbility($ability, $modelClass),
            $abilities,
        );
    }

    private function tableFor(string $modelClass): string
    {
        if (! class_exists($modelClass)) {
            return '';
        }

        $model = new $modelClass;

        return $model instanceof Model ? $model->getTable() : '';
    }
}
