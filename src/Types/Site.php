<?php

declare(strict_types=1);

namespace Gaffer\Types;

class Site
{
    public function name(): string
    {
        return \get_bloginfo('name');
    }

    public function description(): string
    {
        return \get_bloginfo('description');
    }

    public function url(): string
    {
        return \home_url('/');
    }

    public function language(): string
    {
        return \get_locale();
    }

    public function charset(): string
    {
        return \get_bloginfo('charset');
    }

    public function theme_url(): string
    {
        return \get_stylesheet_directory_uri();
    }

    public function language_attributes(): string
    {
        return \get_language_attributes();
    }

    public function is_ssl(): bool
    {
        return \is_ssl();
    }

    public function icon(): ?Image
    {
        $id = (int) \get_option('site_icon');
        if ($id <= 0) {
            return null;
        }
        $attachment = Attachment::from_id($id);
        return $attachment instanceof Image ? $attachment : null;
    }

    public function option(string $key): mixed
    {
        return \get_option($key);
    }
}
