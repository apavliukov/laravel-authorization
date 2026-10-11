<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Fixtures;

use Illuminate\Database\Eloquent\SoftDeletes;

final class SoftDeletingUser extends User
{
    use SoftDeletes;

    protected $table = 'users';
}
