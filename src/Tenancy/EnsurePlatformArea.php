<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tenancy;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform-area fence: the deny-all platform gate (granted only through the
 * super-admin bypass) plus the tenant bypass for the rest of the request.
 *
 * The bypass is entered, not wrapped around $next: Livewire replays persistent
 * middleware through a pipeline whose $next returns immediately, so a
 * try/finally here would leave the bypass before the component action runs.
 * Request state is per process/request (and re-hydrated per queued job), so
 * nothing needs restoring.
 */
final readonly class EnsurePlatformArea
{
    public const string GATE = 'accessPlatformAdmin';

    public function __construct(private CurrentTenant $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        Gate::authorize(self::GATE);

        $this->tenant->enterBypass();

        return $next($request);
    }
}
