<?php

declare(strict_types=1);

namespace Gaffer\Types;

use WP_Post_Type;
class PostType extends Model
{

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

    public static function from_name(string $name): ?static
    {
        $wp = \get_post_type_object($name);
        return $wp instanceof WP_Post_Type ? static::build($wp) : null;
    }

    public static function from(mixed $data): ?static
    {
        return match(true) {
            is_string($data)              => static::from_name($data),
            $data instanceof WP_Post_Type => static::build($data),
            default                       => null,
        };
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
