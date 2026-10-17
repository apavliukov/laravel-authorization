<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests;

use AlexPavliukov\Authorization\Tenancy\SetCurrentTenant;
use AlexPavliukov\Authorization\Tests\Fixtures\Tenancy\Organization;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;

/**
 * Teams-enabled case with tenancy switched on: `Organization` is the tenant and
 * the Spatie team. Wires SetCurrentTenant onto `web` the way an app does in
 * bootstrap/app.php — the package registers no middleware group itself.
 */
abstract class TenancyTestCase extends TeamsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        resolve(Router::class)->pushMiddlewareToGroup('web', SetCurrentTenant::class);
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        tap($app->make('config'), static function (Repository $config): void {
            $config->set('authorization.tenancy.model', Organization::class);
            $config->set('authorization.tenancy.strict', true);
            $config->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::create('organizations', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->softDeletes();
        });

        Schema::table('users', static function (Blueprint $table): void {
            $table->softDeletes();
        });

        Schema::create('posts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('title');
            $table->softDeletes();
        });

        Schema::create('comments', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('post_id');
            $table->string('body');
        });

        Schema::create('reactions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('comment_id');
        });
    }
}
