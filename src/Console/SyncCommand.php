<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Console;

use AlexPavliukov\Authorization\Database\PermissionSync;
use Illuminate\Console\Command;

final class SyncCommand extends Command
{
    protected $signature = 'authorization:sync {--dry-run : Preview the changes without writing them} {--prune : Delete permissions no longer declared by the registry}';

    protected $description = 'Sync permissions and role grants from the authorizable models and role enum';

    public function handle(PermissionSync $sync): int
    {
        $plan = $sync->plan();

        $this->renderPlan($plan);

        if ($this->option('dry-run')) {
            $this->info('Dry run — no changes applied.');

            return self::SUCCESS;
        }

        $prune = (bool) $this->option('prune');

        if (! $prune && $plan['permissions']['remove'] !== []) {
            $this->warn(sprintf(
                '%d stale permission(s) left in place. Re-run with --prune to delete them.',
                count($plan['permissions']['remove']),
            ));
        }

        $sync->apply($prune);

        $this->info('Authorization synced.');

        return self::SUCCESS;
    }

    /**
     * @param  array{
     *   permissions: array{create: list<string>, remove: list<string>},
     *   roles: array<string, array{grant: list<string>, revoke: list<string>}>,
     * }  $plan
     */
    private function renderPlan(array $plan): void
    {
        $this->line('<info>Permissions</info>');
        $this->renderList('create', $plan['permissions']['create']);
        $this->renderList('remove', $plan['permissions']['remove']);

        $this->line('<info>Roles</info>');

        foreach ($plan['roles'] as $roleName => $changes) {
            if ($changes['grant'] === [] && $changes['revoke'] === []) {
                $this->line(sprintf('  %s: up to date', $roleName));

                continue;
            }

            $this->line(sprintf('  %s:', $roleName));
            $this->renderList('grant', $changes['grant']);
            $this->renderList('revoke', $changes['revoke']);
        }
    }

    /** @param  list<string>  $items */
    private function renderList(string $label, array $items): void
    {
        if ($items === []) {
            return;
        }

        $this->line(sprintf('  %s: %s', $label, implode(', ', $items)));
    }
}
