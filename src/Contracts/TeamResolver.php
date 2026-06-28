<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Contracts;

use Illuminate\Http\Request;

interface TeamResolver
{
    public function resolve(Request $request): int|string|null;
}
