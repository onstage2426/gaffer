<?php

declare(strict_types=1);

namespace Gaffer\Factory;

use WP_Post;
use Gaffer\Types\Post;
use Gaffer\Types\Product;

class PostFactory
{
    public function __construct(private readonly array $query_vars = []) {}

    public function from(mixed $data): Post|Product|null
    {
        return match(true) {
            is_int($data)            => $this->from_id($data),
            $data instanceof WP_Post => $this->from_post($data),
            default                  => null,
        };
    }

    public function from_id(int $id): Post|Product|null
    {
        return $this->build(\get_post($id));
    }

    public function from_post(WP_Post $post): Post|Product
    {
        return $this->build($post);
    }

    public function build(?WP_Post $post): Post|Product|null
    {
        if (!$post instanceof \WP_Post) {
            return null;
        }

        if ($post->post_type === "product") {
            return Product::build($post, $this->query_vars);
        }

        return Post::build($post);
    }
}
