<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Template Cache
    |--------------------------------------------------------------------------
    |
    | Compiled Twig templates are cached in storage/cache/views (a folder per
    | Gaffer version) instead of being compiled on every request. On unless
    | false. A changed template compiles again by itself (any change of its
    | modification time or size); a folder the web server can't write renders
    | without the cache.
    |
    */

    // 'cache' => true,

    /*
    |--------------------------------------------------------------------------
    | Block Previews
    |--------------------------------------------------------------------------
    |
    | Show the theme's blocks in the block editor, rendered by their own
    | functions.php and styled with the build's assets/css/editor.css entry
    | (Tailwind without layers). Off unless true: the editor then shows an
    | empty preview and the block's fields.
    |
    */

    // 'block_previews' => false,

    /*
    |--------------------------------------------------------------------------
    | Twig Extensions
    |--------------------------------------------------------------------------
    |
    | Extra classes registered as attribute-based Twig extensions (via
    | #[AsTwigFunction]/#[AsTwigFilter] etc.), on top of Gaffer's own
    | Twig\Extension (config(), ajax_url()), DebugExtension and StringExtension.
    |
    */

    // 'twig_extensions' => [
    //     \Theme\Twig\CustomExtension::class,
    // ],

    /*
    |--------------------------------------------------------------------------
    | Post Type Classes
    |--------------------------------------------------------------------------
    |
    | Map of post_type => class (a subclass of Gaffer\Types\Post). Post::from()
    | and the other Post factories return that class for posts of that type.
    |
    */

    // 'types' => [
    //     'product' => \Theme\Types\Product::class,
    // ],

    /*
    |--------------------------------------------------------------------------
    | Taxonomy Classes
    |--------------------------------------------------------------------------
    |
    | Map of taxonomy => class (a subclass of Gaffer\Types\Term). Term::from()
    | and the other Term factories return that class for terms of that taxonomy.
    |
    */

    // 'terms' => [
    //     'product_cat' => \Theme\Types\ProductCategory::class,
    // ],

];
