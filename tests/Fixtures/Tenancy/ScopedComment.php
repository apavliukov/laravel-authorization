<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Fixtures\Tenancy;

use AlexPavliukov\Authorization\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One hop from its tenant through a ScopedPost; carries its own app scope (bodies starting "hidden").
 *
 * @property string $body
 */
final class ScopedComment extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'comments';

    protected $guarded = [];

    protected static function tenantRelationPath(): string
    {
        return 'post';
    }

    protected static function booted(): void
    {
        self::addGlobalScope('app', static fn (Builder $query): Builder => $query->whereNot('body', 'like', 'hidden%'));
    }

    /** @return BelongsTo<ScopedPost, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(ScopedPost::class, 'post_id');
    }
}
