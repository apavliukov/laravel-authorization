<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tenancy;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Which UI area the request is in — `platform | organization | member | …` per
 * `authorization.areas`. Resolved by matching the route name against the
 * configured patterns — on a Livewire update, the route of the page the component
 * was rendered on. Outside a matching route (or off-request: console, queue) the
 * session view mode (set by a switcher) applies, then the first area.
 * Deliberately knows nothing about the tenant: that is CurrentTenant's job.
 */
final readonly class CurrentArea
{
    public const string SESSION_KEY = 'authorization.area';

    private const string ORIGINAL_ROUTE = 'authorization.original_route';

    public function __construct(private Request $request, private Router $router) {}

    public function current(): ?string
    {
        $areas = $this->areas();

        if ($areas === []) {
            return null;
        }

        $original = $this->request->attributes->get(self::ORIGINAL_ROUTE);
        $routeName = is_string($original) ? $original : ($this->request->route()?->getName() ?? '');

        foreach ($areas as $area => $pattern) {
            if (Str::is($pattern, $routeName)) {
                return $area;
            }
        }

        $fromSession = session()->get(self::SESSION_KEY);

        if (is_string($fromSession) && array_key_exists($fromSession, $areas)) {
            return $fromSession;
        }

        return array_key_first($areas);
    }

    public function is(string $area): bool
    {
        return $this->current() === $area;
    }

    public function set(string $area): void
    {
        if (! array_key_exists($area, $this->areas())) {
            throw new InvalidArgumentException("Unknown area [{$area}]; configure it in authorization.areas.");
        }

        session()->put(self::SESSION_KEY, $area);
    }

    /**
     * Livewire update requests only: remember the name of the route the snapshot
     * was rendered on (path and method from its checksum-verified memo).
     *
     * @internal
     */
    public function rememberOriginalRoute(mixed $path, mixed $method): void
    {
        if (! is_string($path) || ! is_string($method) || $this->request->attributes->has(self::ORIGINAL_ROUTE)) {
            return;
        }

        $server = ['REQUEST_URI' => '/'.ltrim($path, '/'), 'REQUEST_METHOD' => $method] + $this->request->server->all();

        try {
            $route = $this->router->getRoutes()->match($this->request->duplicate(server: $server));
        } catch (HttpException) {
            return;
        }

        $this->request->attributes->set(self::ORIGINAL_ROUTE, $route->getName() ?? '');
    }

    /** @return list<string> */
    public function all(): array
    {
        return array_keys($this->areas());
    }

    /** @return array<string, string> area => route-name pattern (Str::is) */
    private function areas(): array
    {
        $areas = config('authorization.areas', []);

        /** @var array<string, string> */
        return is_array($areas) ? $areas : [];
    }
}
