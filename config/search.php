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
    | taxonomies (taxonomy => field), direct_terms (taxonomies stored without
    | ancestors: a category shows only its own products), fields (field =>
    | 'string'|'int'|'float': what `document` adds), synonyms (equivalences,
    | one_way), rebuild, thumbnail_size and variation_price (products),
    | post_types (posts); doctor checks them against the engine. Gaffer's own
    | keys: card (a template rendered into each document with `post`),
    | card_context ([class, method]: the template's variables for a post),
    | card_variants (variant => [class, method] wrapping the render, e.g. prices
    | excl. VAT), card_variant ([class, method] returning the visitor's variant
    | or null) and document ([class, method]: gets the post and the document,
    | returns the `fields` it adds, e.g. a price per m²). A changed card
    | template, anything it includes, or the file of one of these methods
    | rebuilds the index.
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
    | Optional: limits (page sizes the visitor may pick with ?limit=, per_page
    | among them), facet_values (field => how many values to list, default
    | 100), max_docs (best matches a search ranks over, default 500, at most
    | 5000), index_pages (true: later pages are indexable too),
    | indexable_filters (field => values: a filtered page search engines may
    | index, e.g. ['on_sale' => ['yes']]), base_params (query params that make
    | another page, kept in the canonical and page links, e.g. ['show']) and
    | redirect_params ([class, method]: gets the query, returns it with the
    | theme's old params translated; a different page is a 301).
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
