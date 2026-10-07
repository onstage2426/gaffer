<?php

declare(strict_types=1);

namespace Gaffer\Types;

/**
 * A media library item. Images come back as Image (Attachment::from() and
 * Post::from() both decide by mime type).
 */
class Attachment extends Post
{
    /** The file's URL, null when WordPress can't give one. */
    public function url(): ?string
    {
        return \wp_get_attachment_url($this->wp->ID) ?: null;
    }

    public function mime(): string
    {
        return $this->wp->post_mime_type;
    }
}
