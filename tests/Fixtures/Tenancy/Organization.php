<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Fixtures\Tenancy;

use AlexPavliukov\Authorization\Tenancy\Concerns\HasMembers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int|null $owner_id
 */
final class Organization extends Model
{
    use HasMembers;
    use SoftDeletes;

    public $timestamps = false;

    protected $guarded = [];
}
