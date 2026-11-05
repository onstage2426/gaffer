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
    | post_types (posts); doctor checks them against the engine. Products have
    | price, regular_price, sale_price, on_sale, stock_status, sku and rating
    | built in (from WooCommerce, not meta): a meta line for them is an error.
    |
    | Gaffer's own keys: cards (layout => template, rendered into each
    | document with `post`; the first is the default, the others are the
    | archive's ?view=), card_context ([class, method]: the templates'
    | variables for a post), card_variants (variant => [class, method] wrapping
    | the render, e.g. prices excl. VAT), card_variant ([class, method]
    | returning the visitor's variant or null) and document ([class, method]:
    | gets the post and the document, returns the `fields` it adds, e.g. a
    | price per m²) and labels ([class, method]: returns field => value =>
    | name for fields without terms, e.g. an ACF select's choices; responses
    | carry them like term names). A changed card template, anything it
    | includes, or the file of one of these methods rebuilds the index.
    |
    */

    // 'indexes' => [
    //     'products' => [
    //         'type' => 'products',
    //         'language' => 'nl',
    //         'searchable' => ['title', 'short_description'],
    //         'facets' => ['price', 'category'],
    //         'meta' => ['herkomst' => 'origin'],
    //         'taxonomies' => ['product_cat' => 'category'],
    //         'cards' => ['grid' => 'product/card.twig'],
    //     ],
    // ],

    /*
    |--------------------------------------------------------------------------
    | Archive
    |--------------------------------------------------------------------------
    |
    | WooCommerce's shop, product categories and the shop's search results
    | (?q=) from an index. What it searches: index, per_page, limits (page
    | sizes the visitor picks with ?limit=, per_page among them), sorts (value
    | => label, '' for the default), default_sort, facets, facet_values (field
    | => how many values to list, default 100), max_docs (best matches a search
    | ranks over, default 500, at most 5000), bounds (numeric fields whose
    | range over the whole archive the template gets as search.bounds) and
    | eager (how many cards at the top of each page load their main image
    | eagerly: the card template's first loading="lazy" becomes "eager"; an
    | int, or one per layout of cards: ['grid' => 4, 'list' => 2]).
    |
    | seo: index_pages (true: later pages are indexable too) and
    | indexable_filters (field => values: a filtered shop page search engines
    | may index, e.g. ['on_sale' => ['yes']]). Otherwise only the first page
    | without query, sort or filter is.
    |
    | urls: base_params (query params that make another page, kept in the
    | canonical and page links, e.g. ['show']), redirect_params ([class,
    | method]: gets the query after WooCommerce's params are translated,
    | returns it with the theme's old params translated or dropped; any change
    | is a 301 to what it returned) and redirect_search (true:
    | WordPress's own ?s= search opens the shop's results).
    |
    | Search::archive() gives the template its data; Gaffer adds the redirects
    | (/page/N/, WooCommerce's own params), the canonical URL and robots.
    |
    */

    // 'archive' => [
    //     'index' => 'products',
    //     'per_page' => 24,
    //     'sorts' => ['' => 'Popular', 'price:asc' => 'Price, low to high'],
    //     'default_sort' => ['total_sales:desc'],
    //     'urls' => ['redirect_search' => true],
    // ],

];
