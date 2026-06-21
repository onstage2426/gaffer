<?php

declare(strict_types=1);

namespace Gaffer\Support\Types;

use WP_Post_Type;
use Gaffer\Support\Traits\ClassImporter;

class PostType
{
    use ClassImporter;

    protected string $permalink;
    public string $name;
    public string $label;
    public string $description;
    public bool $public;

    public static function build(WP_Post_Type $wp_post_type): static
    {
        $post_type = new static();
        $post_type->import($wp_post_type);
        return $post_type;
    }

    public function title(): string
    {
        return $this->label;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function link(): string
    {
        if (isset($this->permalink)) {
            return $this->permalink;
        }
        $link = \get_post_type_archive_link($this->name);
        return $this->permalink = $link !== false ? $link : '';
    }

    public function public(): bool
    {
        return $this->public;
    }
}
