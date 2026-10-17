<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Fixtures\Tenancy;

use AlexPavliukov\Authorization\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A post carrying an app scope (titles starting "hidden") and soft deletes — neither may leak into
 * the tenant fence of models that reach their tenant through it.
 *
 * @property int|null $organization_id
 * @property string $title
 */
final class ScopedPost extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    public $timestamps = false;

    protected $table = 'posts';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::addGlobalScope('app', static fn (Builder $query): Builder => $query->whereNot('title', 'like', 'hidden%'));
    }
}
