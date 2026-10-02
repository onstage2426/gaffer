<?php

declare(strict_types=1);

namespace Gaffer\Console\Migrate;

/**
 * A place block content is stored: a post's post_content, or one block widget
 * (the "content" of an entry in the widget_block option).
 */
final readonly class Location
{
    public function __construct(
        public string $kind, // 'post' or 'widget'
        public int $id,
        public string $label,
    ) {}

    /** @return array{kind: string, id: int, label: string} */
    public function to_array(): array
    {
        return ['kind' => $this->kind, 'id' => $this->id, 'label' => $this->label];
    }
}
