<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tenancy;

use AlexPavliukov\Authorization\Support\ScalarKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use RuntimeException;

/**
 * The single source of "which tenant" for the request. State lives in the
 * hidden Laravel Context so it rides along with queued jobs for free; the
 * Spatie permissions team follows the tenant.
 */
final class CurrentTenant
{
    public const string ID_KEY = 'authorization.tenant_id';

    public const string BYPASS_KEY = 'authorization.tenant_bypass';

    private ?Model $model = null;

    public function isConfigured(): bool
    {
        return is_string(config('authorization.tenancy.model'));
    }

    /** @return class-string<Model> */
    public function modelClass(): string
    {
        $model = config('authorization.tenancy.model');

        if (! is_string($model)) {
            throw new RuntimeException('Tenancy is not configured. Set authorization.tenancy.model.');
        }

        /** @var class-string<Model> $model */
        return $model;
    }

    public function foreignKey(): string
    {
        $key = config('authorization.tenancy.foreign_key', 'organization_id');

        return is_string($key) ? $key : 'organization_id';
    }

    public function id(): int|string|null
    {
        return ScalarKey::normalize(Context::getHidden(self::ID_KEY));
    }

    public function get(): ?Model
    {
        $id = $this->id();

        if ($id === null) {
            return null;
        }

        if ($this->model?->getKey() !== $id) {
            $this->model = $this->modelClass()::query()->withoutGlobalScopes()->find($id);
        }

        return $this->model;
    }

    public function set(Model|int|string $tenant): void
    {
        $this->model = $tenant instanceof Model ? $tenant : null;
        $id = $tenant instanceof Model ? ScalarKey::normalize($tenant->getKey()) : $tenant;

        Context::addHidden(self::ID_KEY, $id);
        setPermissionsTeamId($id);
    }

    public function forget(): void
    {
        $this->model = null;
        Context::forgetHidden(self::ID_KEY);
        setPermissionsTeamId(null);
    }

    public function isBypassed(): bool
    {
        return Context::getHidden(self::BYPASS_KEY) === true;
    }

    /**
     * Bypass for the rest of the request — middleware only; code uses withoutTenant().
     *
     * @internal
     */
    public function enterBypass(): void
    {
        Context::addHidden(self::BYPASS_KEY, true);
    }

    /**
     * @template TReturn
     *
     * @param  callable():TReturn  $callback
     * @return TReturn
     */
    public function withoutTenant(callable $callback): mixed
    {
        $previous = $this->isBypassed();
        Context::addHidden(self::BYPASS_KEY, true);

        try {
            return $callback();
        } finally {
            Context::addHidden(self::BYPASS_KEY, $previous);
        }
    }

    /**
     * @template TReturn
     *
     * @param  callable():TReturn  $callback
     * @return TReturn
     */
    public function forTenant(Model|int|string $tenant, callable $callback): mixed
    {
        $previousId = $this->id();
        $previousModel = $this->model;
        $previousBypass = $this->isBypassed();
        $this->set($tenant);
        Context::addHidden(self::BYPASS_KEY, false);

        try {
            return $callback();
        } finally {
            $previousId === null ? $this->forget() : $this->set($previousId);
            $this->model = $previousModel;
            Context::addHidden(self::BYPASS_KEY, $previousBypass);
        }
    }

    /** Re-apply the Spatie team after the Context is restored on a queue worker. */
    public function syncPermissionsTeam(): void
    {
        setPermissionsTeamId($this->id());
    }
}
