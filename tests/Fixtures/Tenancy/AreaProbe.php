<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Fixtures\Tenancy;

use AlexPavliukov\Authorization\Tenancy\CurrentArea;
use Livewire\Component;

final class AreaProbe extends Component
{
    public string $area = '';

    public function load(): void
    {
        $this->area = (string) resolve(CurrentArea::class)->current();
    }

    public function render(): string
    {
        return '<div>area:{{ $area }}</div>';
    }
}
