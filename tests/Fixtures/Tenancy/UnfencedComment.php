<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Fixtures\Tenancy;

use AlexPavliukov\Authorization\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Misconfigured: its path ends at a model without BelongsToTenant. */
final class UnfencedComment extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'comments';

    protected static function tenantRelationPath(): string
    {
        return 'post';
    }

    /** @return BelongsTo<Organization, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'post_id');
    }
}
