<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class MakePolicyCommand extends Command
{
    protected $signature = 'make:authorization-policy {model : The model class short name (e.g. Post)}';

    protected $description = 'Create a policy extending the authorization AbstractPolicy';

    public function handle(): int
    {
        $modelArgument = $this->argument('model');

        if (! is_string($modelArgument)) {
            $this->error('The model argument must be a string.');

            return self::FAILURE;
        }

        $model = Str::studly($modelArgument);
        $targetPath = app_path("Policies/{$model}Policy.php");

        if (File::exists($targetPath)) {
            $this->error("Policy already exists: $targetPath");

            return self::FAILURE;
        }

        $stub = File::get(__DIR__.'/stubs/policy.stub');
        $contents = str_replace('{{ model }}', $model, $stub);

        File::ensureDirectoryExists(app_path('Policies'));
        File::put($targetPath, $contents);

        $this->info("Policy created: $targetPath");

        return self::SUCCESS;
    }
}
