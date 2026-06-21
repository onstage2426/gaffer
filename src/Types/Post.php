<?php

declare(strict_types=1);

namespace Gaffer\Types;

use WP_Post;

use Gaffer\Facades\Theme;
use Gaffer\Types\Attachment;
use Gaffer\Types\Image;
use Gaffer\Types\PostType;
use Gaffer\Factory\PostFactory;
class Post extends Model
{

    protected string $permalink;
    public int $ID;
    public string $post_author;
    public string $post_title;
    public string $post_excerpt;
    public string $post_content;
    public string $post_date;
    public string $post_date_gmt;
    public string $post_status;
    public string $comment_status;
    public string $ping_status;
    public string $post_password;
    public string $post_name;
    public string $to_ping;
    public string $pinged;
    public string $post_modified;
    public string $post_modified_gmt;
    public string $post_content_filtered;
    public int $post_parent;
    public string $guid;
    public int $menu_order;
    public string $post_type;
    public string $post_mime_type;
    public string $comment_count;
    public string $filter;

    public static function build(WP_Post $wp_post): static
    {
        $post = new static();
        $post->ID = $wp_post->ID;
        $post->import($wp_post);
        return $post;
    }

    public function id(): int
    {
        return $this->ID;
    }

    public function title(): string
    {
        return \apply_filters("the_title", $this->post_title, $this->ID);
    }

    public function content(): string
    {
        return \apply_filters("the_content", $this->post_content);
    }

    public function link(): string
    {
        if (isset($this->permalink)) {
            return $this->permalink;
        }
        $link = \get_permalink($this->ID);
        return $this->permalink = $link !== false ? $link : '';
    }

    public function thumbnail_id(): int
    {
        return (int) $this->meta("_thumbnail_id");
    }

    public function thumbnail(): Image
    {
        $id = $this->thumbnail_id();
        return Theme::get_image($id > 0 ? $id : null);
    }

    public function tags(): array
    {
        return $this->terms("post_tag");
    }

    public function categories(): array
    {
        return $this->terms("category");
    }

    public function terms(string|array $taxonomy): array
    {
        return array_map(
            [Theme::class, "get_term"],
            \wp_get_object_terms($this->id(), $taxonomy),
        );
    }

    public function taxonomies(): array
    {
        return \get_post_taxonomies($this->id());
    }

    public function post_type(): string
    {
        return $this->post_type;
    }

    public function post_type_object(): PostType
    {
        return PostType::from_name($this->post_type());
    }

    public function timestamp(): int|false
    {
        return \get_post_timestamp($this->ID);
    }

    public function modified_timestamp(): int|false
    {
        return \get_post_timestamp($this->ID, "modified");
    }

    public function date(?string $date_format = null): string|false
    {
        $format = $date_format ?: \get_option("date_format");

        $date = \wp_date($format, $this->timestamp());
        $date = \apply_filters("get_the_date", $date, $date_format, $this->ID);

        return $date;
    }

    public function modified_date(?string $date_format = null): string|false
    {
        $format = $date_format ?: \get_option("date_format");
        $date   = \wp_date($format, $this->modified_timestamp());
        return \apply_filters("get_the_modified_date", $date, $date_format, $this->ID);
    }

    public function parent(): ?Post
    {
        if (0 === $this->post_parent) {
            return null;
        }

        return (new PostFactory())->from_id($this->post_parent);
    }

    public function children(): array
    {
        return Theme::get_posts(
            [
                "numberposts" => -1,
                "post_type" => $this->post_type,
                "post_status" => "publish",
                "post_parent" => $this->ID,
            ]
        );
    }

    public function comment_count(): int
    {
        return (int) \get_comments_number($this->ID);
    }

    public function status(): string
    {
        return $this->post_status;
    }

    public function mime(): string
    {
        return $this->post_mime_type;
    }

    public function meta(string $key = ""): mixed
    {
        return \get_post_meta($this->ID, $key, "" !== $key);
    }

    public function current(): bool
    {
        return $this->ID === get_the_ID();
    }
}
