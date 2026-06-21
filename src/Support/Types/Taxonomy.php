<?php

declare(strict_types=1);

namespace Gaffer\Support\Types;

use WP_Taxonomy;
use Gaffer\Support\Facades\Theme;
use Gaffer\Support\Traits\ClassImporter;

class Taxonomy
{
    use ClassImporter;

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
