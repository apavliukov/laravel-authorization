<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Feature\Tenancy;

use AlexPavliukov\Authorization\Tenancy\OwnershipException;
use AlexPavliukov\Authorization\Tenancy\TenantMembership;
use AlexPavliukov\Authorization\Tests\Fixtures\SoftDeletingUser;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\Organization;
use AlexPavliukov\Authorization\Tests\OwnershipTestCase;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/** A soft delete is an UPDATE the FK never sees — the listener must catch it. */
#[Group('tenancy')]
final class OwnershipSoftDeletingUserTest extends OwnershipTestCase
{
    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        tap($app->make('config'), static function (Repository $config): void {
            $config->set('auth.providers.users.model', SoftDeletingUser::class);
        });
    }

    #[Test]
    public function soft_deleting_an_owner_is_refused(): void
    {
        $owner = SoftDeletingUser::query()->create(['name' => 'O', 'email' => 'o@example.com', 'password' => 'x']);
        Organization::query()->create(['name' => 'Acme', 'owner_id' => $owner->id]);

        $this->assertThrows(fn () => $owner->delete(), OwnershipException::class);
        $this->assertFalse($owner->fresh()?->trashed());
    }

    #[Test]
    public function a_deleted_user_cannot_become_the_owner(): void
    {
        $owner = SoftDeletingUser::query()->create(['name' => 'O', 'email' => 'o@example.com', 'password' => 'x']);
        $archived = SoftDeletingUser::query()->create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x']);
        $archived->delete();
        $acme = Organization::query()->create(['name' => 'Acme', 'owner_id' => $owner->id]);

        $this->assertThrows(fn () => resolve(TenantMembership::class)->transferOwnership($acme, $archived), OwnershipException::class);
        $this->assertSame($owner->id, $acme->fresh()?->owner_id);
    }
}
