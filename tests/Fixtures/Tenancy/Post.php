<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Fixtures\Tenancy;

use AlexPavliukov\Authorization\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int|null $organization_id
 * @property string $title
 */
final class Post extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = [];
}
