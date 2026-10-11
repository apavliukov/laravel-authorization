<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tenancy;

use DomainException;

/** A write that would leave a tenant without exactly one owner. */
final class OwnershipException extends DomainException
{
    public static function ownerRoleIsDerived(): self
    {
        return new self("The tenant owner's role is managed by the package. Transfer ownership instead.");
    }

    public static function ownerRoleIsReserved(): self
    {
        return new self('The owner role is held only by the tenant owner. Transfer ownership instead.');
    }

    public static function archivedUserCannotOwn(): self
    {
        return new self('A deleted user cannot own a tenant.');
    }

    public static function userOwnsTenants(): self
    {
        return new self('This user owns a tenant and cannot be deleted. Transfer ownership first.');
    }
}
