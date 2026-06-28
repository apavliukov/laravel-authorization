<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Fixtures;

/**
 * App-defined system abilities. The package ships no SystemAbility enum — each
 * consumer declares its own (model-less Gate::define checks). This fixture stands
 * in for that app-side enum in the test suite.
 */
enum SystemAbility: string
{
    case ACCESS_PLATFORM_ADMIN = 'accessPlatformAdmin';
}
