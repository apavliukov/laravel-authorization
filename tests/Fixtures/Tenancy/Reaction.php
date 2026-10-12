<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Fixtures\Tenancy;

use AlexPavliukov\Authorization\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Two hops from its tenant; no table — only tenant() is exercised. */
class Reaction extends Model
{
    use BelongsToTenant;

    protected static function tenantRelationPath(): ?string
    {
        return 'comment.post';
    }
}
