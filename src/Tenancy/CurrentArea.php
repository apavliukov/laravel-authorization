<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tenancy;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Which UI area the request is in — `platform | organization | member | …` per
 * `authorization.areas`. Resolved by matching the route name against the
 * configured patterns; outside a matching route (or off-request: console,
 * queue) the session view mode (set by a switcher) applies, then the first area.
 * Deliberately knows nothing about the tenant: that is CurrentTenant's job.
 */
final readonly class CurrentArea
{
    public const string SESSION_KEY = 'authorization.area';

    public function __construct(private Request $request) {}

    public function current(): ?string
    {
        $areas = $this->areas();

        if ($areas === []) {
            return null;
        }

        $routeName = $this->request->route()?->getName() ?? '';

        foreach ($areas as $area => $pattern) {
            if (Str::is($pattern, $routeName)) {
                return $area;
            }
        }

        $fromSession = $this->request->hasSession() ? $this->request->session()->get(self::SESSION_KEY) : null;

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

        $this->request->session()->put(self::SESSION_KEY, $area);
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
