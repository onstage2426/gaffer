<?php

// Stand-ins for the few WordPress functions unit tests touch.
// Excluded from phpstan, which has the real WordPress stubs.

if (!function_exists('get_pagenum_link')) {
    // Like WordPress: the current query string kept, escaped unless $escape is false.
    function get_pagenum_link(int $page = 1, bool $escape = true): string
    {
        $url = "/page/{$page}/" . ($GLOBALS['gaffer_test_query'] ?? '');

        return $escape ? str_replace('&', '&#038;', $url) : $url;
    }
}

if (!function_exists('wp_upload_dir')) {
    /** @return array{basedir: string, baseurl: string} */
    function wp_upload_dir(?string $time = null, bool $create_dir = true, bool $refresh_cache = false): array
    {
        // The theme under test's own uploads/, so a test's theme folder holds it and its cleanup removes it.
        return ['basedir' => \Gaffer\Paths::base('uploads'), 'baseurl' => 'https://site.test/wp-content/uploads'];
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        return $value;
    }
}

// Nothing exists in unit tests: lookups find nothing (the 404 path).
if (!function_exists('get_post')) {
    function get_post(mixed $post = null): null
    {
        return null;
    }
}

if (!function_exists('get_term')) {
    function get_term(mixed $term = null): null
    {
        return null;
    }
}

// Gravity Forms' field class, for GravityForm's input mapping tests.
if (!class_exists('GF_Field')) {
    class GF_Field
    {
        public $id;
        public $type;
        public $label = '';
        public $adminLabel = '';
        public $isRequired = false;
        public $inputs = null;
        public $choices = null;
        public $multipleFiles = false;
        public $maxFiles = '';
        public $allowedExtensions = '';
        public $maxFileSize = '';
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return $GLOBALS['gaffer_test_admin'] ?? false;
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return $url;
    }
}

if (!function_exists('get_template_directory')) {
    function get_template_directory(): string
    {
        return \Gaffer\Paths::base();
    }
}

if (!function_exists('get_template_directory_uri')) {
    function get_template_directory_uri(): string
    {
        return 'https://site.test/wp-content/themes/t';
    }
}

if (!function_exists('__')) {
    // A "translation" that shows the domain it was asked for.
    function __(string $text, string $domain = 'default'): string
    {
        return "{$domain}:{$text}";
    }
}

if (!function_exists('wp_date')) {
    function wp_date(string $format, ?int $timestamp = null): string
    {
        return gmdate($format, $timestamp ?? time());
    }
}

if (!function_exists('wp_get_environment_type')) {
    function wp_get_environment_type(): string
    {
        return 'development';
    }
}

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return false;
    }
}
