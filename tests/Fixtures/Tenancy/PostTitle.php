<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Fixtures\Tenancy;

use Livewire\Component;

final class PostTitle extends Component
{
    public Post $post;

    public string $title = '';

    public function load(): void
    {
        $this->title = $this->post->title;
    }

    public function render(): string
    {
        return '<div>title:{{ $title }}</div>';
    }
}
