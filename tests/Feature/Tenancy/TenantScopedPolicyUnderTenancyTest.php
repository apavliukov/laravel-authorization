<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Feature\Tenancy;

use AlexPavliukov\Authorization\Tenancy\CurrentTenant;
use AlexPavliukov\Authorization\TenantScopedPolicy;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\Organization;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\Post;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\PostPolicy;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use AlexPavliukov\Authorization\Tests\TenancyTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;

#[Group('tenancy')]
#[CoversClass(TenantScopedPolicy::class)]
final class TenantScopedPolicyUnderTenancyTest extends TenancyTestCase
{
    #[Test]
    public function the_policy_fences_to_the_current_tenant_without_a_resolver_closure(): void
    {
        Permission::findOrCreate('view posts', 'web');
        $acme = Organization::query()->create(['name' => 'Acme']);
        $globex = Organization::query()->create(['name' => 'Globex']);
        $user = User::factory()->create();

        $tenant = resolve(CurrentTenant::class);
        $tenant->forTenant($acme, static fn () => $user->givePermissionTo('view posts'));
        [$own, $foreign] = $tenant->withoutTenant(static fn (): array => [
            Post::query()->create(['organization_id' => $acme->id, 'title' => 'a']),
            Post::query()->create(['organization_id' => $globex->id, 'title' => 'g']),
        ]);

        $tenant->set($acme);

        $this->assertTrue(resolve(PostPolicy::class)->view($user, $own));
        $this->assertFalse(resolve(PostPolicy::class)->view($user, $foreign));
    }
}
