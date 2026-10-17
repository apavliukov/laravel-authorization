<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Fixtures\Tenancy;

use AlexPavliukov\Authorization\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Its path ends at a model that is itself transitively owned (ScopedComment → post). */
final class Vote extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'reactions';

    protected $guarded = [];

    protected static function tenantRelationPath(): string
    {
        return 'comment';
    }

    /** @return BelongsTo<ScopedComment, $this> */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(ScopedComment::class);
    }
}
