<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Fixtures\Tenancy;

use AlexPavliukov\Authorization\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Transitively owned: reaches its tenant through `post`. */
class Comment extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = [];

    protected static function tenantRelationPath(): ?string
    {
        return 'post';
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
