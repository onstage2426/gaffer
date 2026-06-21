<?php

declare(strict_types=1);

namespace Gaffer\Factory;

use WP_Post;
use Gaffer\Support\Types\Attachment;
use Gaffer\Support\Types\Image;

class AttachmentFactory
{
    public function from(mixed $data): Attachment|Image|null
    {
        return match(true) {
            is_int($data)            => $this->from_id($data),
            $data instanceof WP_Post => $this->from_post($data),
            default                  => null,
        };
    }

    public function from_id(int $id): Attachment|Image|null
    {
        return $this->build(\get_post($id));
    }

    public function from_post(WP_Post $post): Attachment|Image
    {
        return $this->build($post);
    }

    public function build(?WP_Post $post): Attachment|Image|null
    {
        if (!$post instanceof \WP_Post) {
            return null;
        }

        if (str_contains($post->post_mime_type, "image")) {
            return Image::build($post);
        }

        return Attachment::build($post);
    }
}
