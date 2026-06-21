<?php

declare(strict_types=1);

namespace Gaffer\Types;

use WP_Taxonomy;
use Gaffer\Facades\Theme;
class Taxonomy extends Model
{

    public string $name;
    public string $label;
    public string $description;
    public bool $public;
    public bool $hierarchical;

    public static function build(WP_Taxonomy $wp_taxonomy): static
    {
        $taxonomy = new static();
        $taxonomy->import($wp_taxonomy);
        return $taxonomy;
    }

    public static function from_name(string $name): ?static
    {
        $wp = \get_taxonomy($name);
        return $wp instanceof WP_Taxonomy ? static::build($wp) : null;
    }

    public static function from(mixed $data): ?static
    {
        return match(true) {
            is_string($data)            => static::from_name($data),
            $data instanceof WP_Taxonomy => static::build($data),
            default                     => null,
        };
    }

    public function name(): string
    {
        return $this->name;
    }

    public function title(): string
    {
        return $this->label;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function public(): bool
    {
        return $this->public;
    }

    public function hierarchical(): bool
    {
        return $this->hierarchical;
    }

    public function terms(): array
    {
        return Theme::get_terms($this->name);
    }
}
