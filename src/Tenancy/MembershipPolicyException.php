<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tenancy;

use DomainException;

/** A write refused by `authorization.tenancy.memberships`. */
final class MembershipPolicyException extends DomainException
{
    public static function singleMembership(): self
    {
        return new self('This user already belongs to another tenant.');
    }
}
