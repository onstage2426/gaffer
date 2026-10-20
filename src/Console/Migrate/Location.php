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

    /**
     * $owner and $name (a meta row's post or term and meta_key, an option's option_name) are
     * set where a row may be deleted: they're what a rollback inserts it again with.
     */
    public function __construct(
        public string $kind, // 'post', 'widget', 'postmeta', 'termmeta' or 'option'
        public int $id,
        public string $label,
        public ?int $owner = null,
        public ?string $name = null,
    ) {}

    /** @return array{kind: string, id: int, label: string, owner?: int, name?: string} */
    public function to_array(): array
    {
        return ['kind' => $this->kind, 'id' => $this->id, 'label' => $this->label,
            ...($this->owner !== null ? ['owner' => $this->owner] : []), ...($this->name !== null ? ['name' => $this->name] : [])];
    }
}
