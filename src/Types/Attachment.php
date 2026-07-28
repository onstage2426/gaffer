<?php

declare(strict_types=1);

namespace Gaffer\Types;

use WP_Post;

class Attachment extends Post
{
    public static function from_id(int $id): Attachment|Image|Video|null
    {
        $post = \get_post($id);
        if (!$post instanceof WP_Post) {
            return null;
        }
        if (str_contains($post->post_mime_type, 'image')) {
            return Image::build($post);
        }
        if (str_contains($post->post_mime_type, 'video')) {
            return Video::build($post);
        }
        return static::build($post);
    }

    public function src(): string
    {
        $url = \wp_get_attachment_url($this->ID);
        return $url ?: "";
    }
}
