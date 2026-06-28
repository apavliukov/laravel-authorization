<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Fixtures;

use AlexPavliukov\Authorization\AbstractPolicy;

final readonly class UserPolicy extends AbstractPolicy
{
    protected function getModelClass(): string
    {
        return User::class;
    }
}
