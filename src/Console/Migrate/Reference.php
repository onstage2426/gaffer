<?php

declare(strict_types=1);

namespace Gaffer\Console\Migrate;

/**
 * One stored ACF field reference: the "_name" row next to a value (postmeta, termmeta or
 * an option), the field key it holds, and what it belongs to: the post type (a revision's
 * parent's), the taxonomy, or "option".
 *
 * @internal
 */
final readonly class Reference
{
    public function __construct(
        public Location $location,
        public string $name, // meta_key or option_name, with its leading "_"
        public string $key,
        public string $owner_type,
        public ?int $owner, // post or term ID; null for options
    ) {}

    /** "product 10658 _aantal_label" */
    public function label(): string
    {
        return $this->location->label;
    }
}
