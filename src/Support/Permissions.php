<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Support;

use AlexPavliukov\Authorization\PermissionRegistry;
use BackedEnum;
use LogicException;

/**
 * Fluent builder for a role's permission-name list, so `AuthorizationRole::permissions()`
 * reads as intent rather than registry plumbing. Resolves names through
 * `PermissionRegistry`, the single source of permission-name truth.
 */
final class Permissions
{
    /** @var list<string> */
    private array $names = [];

    /** @var class-string|null */
    private ?string $model = null;

    public static function make(): self
    {
        return new self;
    }

    /** @param class-string $modelClass */
    public function for(string $modelClass): self
    {
        $this->model = $modelClass;

        return $this;
    }

    public function only(BackedEnum ...$abilities): self
    {
        $model = $this->model ?? throw new LogicException('Call for() before only().');

        foreach ($abilities as $ability) {
            $this->names[] = $this->registry()->nameFromAbility($ability, $model);
        }

        return $this;
    }

    /** @param  class-string  ...$modelClasses */
    public function forAll(string ...$modelClasses): self
    {
        foreach ($modelClasses as $modelClass) {
            foreach ($this->registry()->permissionsFor($modelClass) as $name) {
                $this->names[] = $name;
            }
        }

        return $this;
    }

    /** @return list<string> */
    public function all(): array
    {
        return array_values(array_unique($this->names));
    }

    private function registry(): PermissionRegistry
    {
        return resolve(PermissionRegistry::class);
    }
}
