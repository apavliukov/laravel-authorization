<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Fixtures;

use AlexPavliukov\Authorization\Concerns\HasPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasPolicy;
    use HasRoles;

    protected $guarded = [];

    /** @return Factory<User> */
    protected static function newFactory(): Factory
    {
        return UserFactory::new();
    }
}
