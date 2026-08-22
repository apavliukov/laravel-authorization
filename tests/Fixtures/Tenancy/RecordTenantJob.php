<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Fixtures\Tenancy;

use AlexPavliukov\Authorization\Tenancy\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

final class RecordTenantJob implements ShouldQueue
{
    use Queueable;

    public function handle(CurrentTenant $tenant): void
    {
        Cache::put('job.seen', [
            'tenant' => $tenant->id(),
            'team' => getPermissionsTeamId(),
            'bypassed' => $tenant->isBypassed(),
            'posts' => Post::query()->count(),
        ]);
    }
}
