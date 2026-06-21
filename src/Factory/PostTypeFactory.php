<?php

declare(strict_types=1);

namespace Gaffer\Factory;

use WP_Post_Type;
use Gaffer\Support\Types\PostType;

class PostTypeFactory
{
    public function from(mixed $data): ?PostType
    {
        return match(true) {
            is_string($data)                  => $this->from_name($data),
            $data instanceof WP_Post_Type     => $this->from_post_type($data),
            default                           => null,
        };
    }

    public function from_name(string $name): ?PostType
    {
        return $this->build(\get_post_type_object($name));
    }

    public function from_post_type(WP_Post_Type $post_type): PostType
    {
        return $this->build($post_type);
    }

    public function build(?WP_Post_Type $post_type): ?PostType
    {
        if (!$post_type instanceof \WP_Post_Type) {
            return null;
        }

        return PostType::build($post_type);
    }
}
