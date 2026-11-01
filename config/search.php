<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Indexes
    |--------------------------------------------------------------------------
    |
    | The search indexes of the gaffer-search plugin, by name. Each has a type
    | ('products' or 'posts') and the engine's definition keys: language,
    | searchable, facets, sortable, meta (meta key => field or [field, type]),
    | taxonomies (taxonomy => field), synonyms (equivalences, one_way), rebuild,
    | thumbnail_size and variation_price (products), post_types (posts); doctor
    | checks them against the engine. Gaffer's own keys: card (a template
    | rendered into each document with `post`), card_context ([class, method]:
    | the template's variables for a post), card_variants (variant => [class,
    | method] wrapping the render, e.g. prices excl. VAT) and card_variant
    | ([class, method] returning the visitor's variant or null). A changed card
    | template, or anything it includes, rebuilds the index.
    |
    */

    // 'indexes' => [
    //     'products' => [
    //         'type' => 'products',
    //         'language' => 'nl',
    //         'searchable' => ['title', 'short_description'],
    //         'facets' => ['price', 'category'],
    //         'meta' => ['_price' => ['price', 'float']],
    //         'taxonomies' => ['product_cat' => 'category'],
    //         'card' => 'product/card.twig',
    //     ],
    // ],

    /*
    |--------------------------------------------------------------------------
    | Archive
    |--------------------------------------------------------------------------
    |
    | WooCommerce's shop, product categories and the shop's search results
    | (?q=) from an index: index, per_page, sorts (value => label, '' for the
    | default), default_sort, facets, and redirect_search (true: WordPress's
    | own ?s= search opens the shop's results). Search::archive() gives the
    | template its data; Gaffer adds the redirects (/page/N/, WooCommerce's
    | own params), the canonical URL and robots.
    |
    */

    // 'archive' => [
    //     'index' => 'products',
    //     'per_page' => 24,
    //     'sorts' => ['' => 'Popular', 'price:asc' => 'Price, low to high'],
    //     'default_sort' => ['total_sales:desc'],
    //     'redirect_search' => true,
    // ],

];
