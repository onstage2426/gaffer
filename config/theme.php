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
    | Post Type Classes
    |--------------------------------------------------------------------------
    |
    | Map of post_type => class (a subclass of Gaffer\Types\Post). Post::from()
    | and the other Post factories return that class for posts of that type.
    |
    */

    // 'types' => [
    //     'product' => \App\Types\Product::class,
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
    //     'product_cat' => \App\Types\ProductCategory::class,
    // ],

];
