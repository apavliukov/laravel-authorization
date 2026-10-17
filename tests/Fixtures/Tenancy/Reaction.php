<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Fixtures\Tenancy;

use AlexPavliukov\Authorization\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Two hops from its tenant, through a ScopedComment and its ScopedPost. */
class Reaction extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = [];

    protected static function tenantRelationPath(): ?string
    {
        return 'comment.post';
    }

    /** @return BelongsTo<ScopedComment, $this> */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(ScopedComment::class);
    }
}
