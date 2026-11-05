<?php
// wp eval-file archives.php <out.json> [<cases.json>] (run in the theme folder; --url=<site> on a multisite): each archive case's total and ordered IDs,
// through the engine as Gaffer's archive asks it. Without cases.json the cases are chosen
// (biggest category, a price range around the middle) and written next to out.json.
(function () use ($args) {
    $engine = apply_filters('gaffer_search_engine', null, 1);
    if ($engine === null) { fwrite(STDERR, "no engine\n"); exit(1); }
    $out   = $args[0];
    $casesFile = $args[1] ?? preg_replace('/\.json$/', '.cases.json', $out);
    // The archive's index and default order from config/search.php (Gaffer), else products.
    $archive = class_exists(\Gaffer\Config::class) ? (array) \Gaffer\Config::get('search.archive', []) : [];
    $index   = (string) ($archive['index'] ?? 'products');
    $base    = ['visibility' => 'catalog', 'default_sort' => (array) ($archive['default_sort'] ?? ['total_sales:desc']), 'attributes' => ['id']];
    if (is_file($casesFile)) {
        $cases = json_decode(file_get_contents($casesFile), true);
    } else {
        $cats = get_terms(['taxonomy' => 'product_cat', 'orderby' => 'count', 'order' => 'DESC', 'number' => 1, 'hide_empty' => true]);
        $all  = $engine->search($index, [...$base, 'limit' => 1, 'facets' => ['price']]);
        $min  = (float) ($all['facetStats']['price']['min'] ?? 0); $max = (float) ($all['facetStats']['price']['max'] ?? 100);
        $lo   = round($min + ($max - $min) * 0.2, 2); $hi = round($min + ($max - $min) * 0.6, 2);
        $q    = '';
        foreach ($engine->search($index, [...$base, 'limit' => 1, 'attributes' => ['id', 'title']])['results'] as $hit) {
            $q = explode(' ', trim((string) ($hit['title'] ?? '')))[0];
        }
        $cases = [
            'shop'              => [],
            'category'          => is_array($cats) && $cats ? ['term' => $cats[0]->term_id] : [],
            'price_asc'         => ['sort' => ['price:asc']],
            'price_desc'        => ['sort' => ['price:desc']],
            'category_price_asc'=> (is_array($cats) && $cats ? ['term' => $cats[0]->term_id] : []) + ['sort' => ['price:asc']],
            'price_range'       => ['filter' => ['price' => ['min' => $lo, 'max' => $hi]]],
            'in_stock'          => ['filter' => ['stock_status' => 'instock']],
            'on_sale'           => ['filter' => ['on_sale' => 'yes']],
            'search'            => ['query' => mb_strtolower($q)],
            'search_price_desc' => ['query' => mb_strtolower($q), 'sort' => ['price:desc']],
        ];
        file_put_contents($casesFile, json_encode($cases, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    $result = [];
    foreach ($cases as $name => $case) {
        $ids = []; $offset = 0; $total = null; $warnings = [];
        do {
            $r = $engine->search($index, [...$base, ...$case, 'limit' => 100, 'offset' => $offset]);
            $total ??= $r['total'];
            $warnings = [...$warnings, ...($r['warnings'] ?? [])];
            foreach ($r['results'] as $hit) { $ids[] = $hit['id']; }
            $offset += 100;
        } while ($r['results'] !== [] && $offset < $total && $offset < 2000);
        $result[$name] = ['case' => $case, 'total' => $total, 'ids' => $ids, 'warnings' => array_values(array_unique($warnings))];
    }
    file_put_contents($out, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    foreach ($result as $name => $r) { echo str_pad($name, 20), str_pad((string) $r['total'], 6), count($r['ids']), ' ', implode(',', array_slice($r['ids'], 0, 6)), $r['warnings'] ? '  ! ' . implode(' | ', $r['warnings']) : '', "\n"; }
})();
