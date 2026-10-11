<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests;

use AlexPavliukov\Authorization\Tests\Fixtures\Role;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;

/** Tenancy with ownership on: `organizations.owner_id` + `Role::OWNER`. */
abstract class OwnershipTestCase extends TenancyTestCase
{
    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        tap($app->make('config'), static function (Repository $config): void {
            $config->set('authorization.tenancy.owner_role', Role::OWNER);
        });
    }
}
