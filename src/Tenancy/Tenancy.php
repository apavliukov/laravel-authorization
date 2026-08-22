<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;

/**
 * @method static bool isConfigured()
 * @method static class-string<Model> modelClass()
 * @method static string foreignKey()
 * @method static int|string|null id()
 * @method static Model|null get()
 * @method static void set(Model|int|string $tenant)
 * @method static void forget()
 * @method static bool isBypassed()
 * @method static mixed withoutTenant(callable $callback)
 * @method static mixed forTenant(Model|int|string $tenant, callable $callback)
 *
 * @see CurrentTenant
 */
final class Tenancy extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CurrentTenant::class;
    }
}
