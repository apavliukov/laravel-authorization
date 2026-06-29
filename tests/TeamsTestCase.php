<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;

/**
 * Base case for teams-enabled tests. Rebuilds `model_has_roles` with a NULLABLE
 * `team_id` (a unique index instead of a primary key) so global role assignments
 * (`team_id IS NULL`) can be stored — the schema this package recommends for
 * platform-level roles, which the stock Spatie teams migration does not allow.
 */
abstract class TeamsTestCase extends TestCase
{
    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        tap($app->make('config'), static function (Repository $config): void {
            $config->set('permission.teams', true);
            $config->set('permission.testing', true);
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $pivotTable = config('permission.table_names.model_has_roles');
        $pivotTable = is_string($pivotTable) ? $pivotTable : 'model_has_roles';

        Schema::drop($pivotTable);

        Schema::create($pivotTable, static function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('team_id')->nullable();
            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
            $table->unique(['team_id', 'role_id', 'model_id', 'model_type'], 'model_has_roles_team_role_model_unique');
        });
    }
}
