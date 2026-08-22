<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Fixtures\Tenancy;

use Illuminate\Database\Eloquent\Model;

final class Organization extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
