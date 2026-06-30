<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Feature;

use AlexPavliukov\Authorization\Authorization;
use AlexPavliukov\Authorization\AuthorizationManager;
use AlexPavliukov\Authorization\TenantScopedPolicy;
use AlexPavliukov\Authorization\Tests\Fixtures\TenantScopedUserPolicy;
use AlexPavliukov\Authorization\Tests\Fixtures\User;
use AlexPavliukov\Authorization\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;

#[Group('policies')]
#[CoversClass(TenantScopedPolicy::class)]
final class TenantScopedPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::table('users', static function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->nullable();
        });

        Authorization::resolveTenantUsing(static function (User $user): ?int {
            $tenantId = $user->getAttribute('tenant_id');

            return is_int($tenantId) ? $tenantId : null;
        });
        Authorization::tenantColumn('tenant_id');

        Permission::findOrCreate('view users', 'web');
    }

    #[Test]
    public function it_grants_within_the_same_tenant(): void
    {
        $actor = $this->tenantUser(1);
        $actor->givePermissionTo('view users');

        $this->assertTrue(resolve(TenantScopedUserPolicy::class)->view($actor, $this->tenantUser(1)));
    }

    #[Test]
    public function it_denies_across_tenants_despite_the_permission(): void
    {
        $actor = $this->tenantUser(1);
        $actor->givePermissionTo('view users');

        $this->assertFalse(resolve(TenantScopedUserPolicy::class)->view($actor, $this->tenantUser(2)));
    }

    #[Test]
    public function model_less_checks_ignore_the_tenant(): void
    {
        Permission::findOrCreate('view any users', 'web');
        $actor = $this->tenantUser(1);
        $actor->givePermissionTo('view any users');

        $this->assertTrue(resolve(TenantScopedUserPolicy::class)->viewAny($actor));
    }

    #[Test]
    public function current_tenant_throws_when_the_resolver_is_not_configured(): void
    {
        $this->expectException(RuntimeException::class);

        (new AuthorizationManager)->currentTenant($this->tenantUser(1));
    }

    private function tenantUser(int $tenantId): User
    {
        $user = User::factory()->create();
        $user->setAttribute('tenant_id', $tenantId);
        $user->save();

        return $user;
    }
}
