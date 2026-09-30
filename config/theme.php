<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | When true, enables Twig's DebugExtension (adds the {% dump %} tag and
    | dump() function), Twig's own internal debug mode, and strict_variables
    | (undefined variables, attributes and methods throw instead of silently
    | rendering empty). Defaults to false.
    |
    */

    // 'debug' => false,

    /*
    |--------------------------------------------------------------------------
    | Template Cache
    |--------------------------------------------------------------------------
    |
    | When true, compiled Twig templates are cached to storage/cache/views
    | instead of being recompiled on every request. Defaults to false.
    |
    */

    // 'cache' => false,

    /*
    |--------------------------------------------------------------------------
    | Twig Extensions
    |--------------------------------------------------------------------------
    |
    | Extra classes registered as attribute-based Twig extensions (via
    | #[AsTwigFunction]/#[AsTwigFilter] etc.), on top of the built-in
    | ThemeExtension, DebugExtension, and StringExtension.
    |
    */

    // 'twig_extensions' => [
    //     \App\Twig\CustomExtension::class,
    // ],

    /*
    |--------------------------------------------------------------------------
    | Ajax Namespace
    |--------------------------------------------------------------------------
    |
    | Namespace prefix an ajax "{action}" name is resolved against, i.e.
    | "{namespace}\{action}\{action}". Purely a resolution string, not a real
    | PSR-4 root (Ajax::handle() require_once's the file directly), so it's
    | safe to leave at the default unless it collides with an existing class.
    | Defaults to "Theme\Ajax".
    |
    */

    // 'ajax_namespace' => 'Theme\\Ajax',

    /*
    |--------------------------------------------------------------------------
    | Image Fallback
    |--------------------------------------------------------------------------
    |
    | Attachment ID used by Theme::get_image() when a given (truthy) image
    | ID doesn't resolve to a valid image. A falsy id passed in still
    | returns null without consulting this fallback. Unset means no fallback.
    |
    */

    // 'image_fallback' => 0,

    /*
    |--------------------------------------------------------------------------
    | Post Type Model Overrides
    |--------------------------------------------------------------------------
    |
    | Map of post_type => FQCN, letting TypeResolver build a custom Post
    | subclass for a given post type instead of the base Gaffer\Types\Post.
    |
    */

    // 'types' => [
    //     'product' => \App\Types\Product::class,
    // ],

    /*
    |--------------------------------------------------------------------------
    | Taxonomy Model Overrides
    |--------------------------------------------------------------------------
    |
    | Map of taxonomy => FQCN, letting TypeResolver build a custom Term
    | subclass for a given taxonomy instead of the base Gaffer\Types\Term.
    |
    */

    // 'terms' => [
    //     'product_cat' => \App\Types\ProductCategory::class,
    // ],

];
