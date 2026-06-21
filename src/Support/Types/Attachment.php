<?php

declare(strict_types=1);

namespace Gaffer\Support\Types;

use WP_Post;

class Attachment extends Post
{
    public function src(): string
    {
        $url = \wp_get_attachment_url($this->ID);
        return $url ?: "";
    }
}
