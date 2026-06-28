<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Support;

use AlexPavliukov\Authorization\AuthorizationManager;
use AlexPavliukov\Authorization\Contracts\BypassStrategy;
use BackedEnum;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Default bypass: holders of a super-admin role (per the role enum's
 * AuthorizationRole::isSuperAdmin()) bypass every gate check, except abilities
 * explicitly listed as protected, which always fall through to policies.
 *
 * Protected abilities exist only for genuine authorization-level carve-outs
 * (separation-of-duties, break-glass). Real business "can't"s belong in the
 * Action/domain layer, not here.
 */
final readonly class RoleBypass implements BypassStrategy
{
    /** @param array<int, BackedEnum> $protected abilities always routed to policies, matched by enum value */
    public function __construct(
        private AuthorizationManager $manager,
        private array $protected = [],
    ) {}

    public function shouldBypass(Authenticatable $user, string $ability): bool
    {
        if ($this->isProtected($ability)) {
            return false;
        }

        $superAdminRoleNames = array_map(
            static fn (BackedEnum $role): string => (string) $role->value,
            $this->manager->superAdminRoles(),
        );

        /** @phpstan-ignore method.notFound (the package requires the user to use Spatie\Permission\Traits\HasRoles) */
        return $user->hasAnyRole($superAdminRoleNames);
    }

    private function isProtected(string $ability): bool
    {
        foreach ($this->protected as $protectedAbility) {
            if ((string) $protectedAbility->value === $ability) {
                return true;
            }
        }

        return false;
    }
}
