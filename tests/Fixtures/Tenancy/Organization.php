<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Fixtures\Tenancy;

use AlexPavliukov\Authorization\Tenancy\Concerns\HasMembers;
use Illuminate\Database\Eloquent\Model;

final class Organization extends Model
{
    use HasMembers;

    public $timestamps = false;

    protected $guarded = [];
}
