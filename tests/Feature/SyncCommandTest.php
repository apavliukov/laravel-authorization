<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Feature;

use AlexPavliukov\Authorization\Console\SyncCommand;
use AlexPavliukov\Authorization\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;

#[Group('policies')]
#[CoversClass(SyncCommand::class)]
final class SyncCommandTest extends TestCase
{
    #[Test]
    public function it_applies_the_sync(): void
    {
        $exitCode = Artisan::call('authorization:sync');

        $this->assertSame(0, $exitCode);
        $this->assertSame(7, Permission::query()->count());
    }

    #[Test]
    public function a_dry_run_makes_no_changes(): void
    {
        $exitCode = Artisan::call('authorization:sync', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, Permission::query()->count());
    }

    #[Test]
    public function prune_removes_undeclared_permissions(): void
    {
        Permission::query()->create(['name' => 'legacy ability', 'guard_name' => 'web']);

        $exitCode = Artisan::call('authorization:sync', ['--prune' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseMissing('permissions', ['name' => 'legacy ability']);
        $this->assertDatabaseHas('permissions', ['name' => 'view any users']);
    }
}
