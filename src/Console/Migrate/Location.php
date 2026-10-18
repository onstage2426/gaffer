<?php

declare(strict_types=1);

namespace Gaffer\Console\Migrate;

/**
 * A place content is stored: a post's post_content, one block widget (the
 * "content" of an entry in the widget_block option), or one ACF field reference
 * (the "_name" row next to a value) in postmeta, termmeta or options, by its row ID.
 */
final readonly class Location
{
    public const array KINDS = ['post', 'widget', 'postmeta', 'termmeta', 'option'];

    public function __construct(
        public string $kind, // 'post', 'widget', 'postmeta', 'termmeta' or 'option'
        public int $id,
        public string $label,
    ) {}

    /** @return array{kind: string, id: int, label: string} */
    public function to_array(): array
    {
        return ['kind' => $this->kind, 'id' => $this->id, 'label' => $this->label];
    }
}
