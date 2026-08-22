<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tenancy;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** 403 (or redirect to `authorization.tenancy.missing_tenant_redirect`) when no tenant is active. */
final readonly class EnsureTenantSelected
{
    public function __construct(private CurrentTenant $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->tenant->id() !== null) {
            return $next($request);
        }

        $route = config('authorization.tenancy.missing_tenant_redirect');

        if (is_string($route)) {
            return redirect()->route($route);
        }

        abort(403, 'No tenant selected.');
    }
}
