<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Feature\Tenancy;

use AlexPavliukov\Authorization\Tenancy\CurrentTenant;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\Organization;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\Post;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\RecordTenantJob;
use AlexPavliukov\Authorization\Tests\TenancyTestCase;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('tenancy')]
#[CoversClass(CurrentTenant::class)]
final class QueuePropagationTest extends TenancyTestCase
{
    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        tap($app->make('config'), static function (Repository $config): void {
            $config->set('queue.default', 'database');
            $config->set('cache.default', 'array');
        });
    }

    #[Test]
    public function the_tenant_is_restored_on_the_worker(): void
    {
        $acme = Organization::query()->create(['name' => 'Acme']);
        $globex = Organization::query()->create(['name' => 'Globex']);
        resolve(CurrentTenant::class)->withoutTenant(static function () use ($acme, $globex): void {
            Post::query()->create(['organization_id' => $acme->id, 'title' => 'a']);
            Post::query()->create(['organization_id' => $globex->id, 'title' => 'g']);
        });

        resolve(CurrentTenant::class)->set($acme);
        RecordTenantJob::dispatch();
        resolve(CurrentTenant::class)->forget();

        $this->assertSame(0, Artisan::call('queue:work', ['--once' => true, '--no-interaction' => true]));

        $this->assertSame(
            ['tenant' => $acme->id, 'team' => $acme->id, 'bypassed' => false, 'posts' => 1],
            Cache::get('job.seen'),
        );
    }

    #[Test]
    public function a_job_dispatched_inside_without_tenant_runs_bypassed(): void
    {
        resolve(CurrentTenant::class)->withoutTenant(static function (): void {
            Post::query()->create(['organization_id' => 1, 'title' => 'a']);
            Post::query()->create(['organization_id' => 2, 'title' => 'g']);
            RecordTenantJob::dispatch();
        });

        $this->assertSame(0, Artisan::call('queue:work', ['--once' => true, '--no-interaction' => true]));

        $this->assertSame(
            ['tenant' => null, 'team' => null, 'bypassed' => true, 'posts' => 2],
            Cache::get('job.seen'),
        );
    }
}
