<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tenancy;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Resolves the tenant for the request and activates it (and its permissions team). */
final readonly class SetCurrentTenant
{
    public function __construct(private TenantResolver $resolver, private CurrentTenant $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $id = $this->resolver->resolve($request);

        $id === null ? $this->tenant->forget() : $this->tenant->set($id);

        return $next($request);
    }
}
