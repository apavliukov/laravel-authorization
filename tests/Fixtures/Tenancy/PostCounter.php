<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Fixtures\Tenancy;

use Livewire\Component;

final class PostCounter extends Component
{
    public int $count = -1;

    public function load(): void
    {
        $this->count = Post::query()->count();
    }

    public function render(): string
    {
        return '<div>count:{{ $count }}</div>';
    }
}
