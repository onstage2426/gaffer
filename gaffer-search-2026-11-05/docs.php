<?php
// wp eval-file docs.php <out.json>: every stored document's price, stock, sku, rating, on_sale fields.
(function () use ($args) {
    $engine = apply_filters('gaffer_search_engine', null, 1);
    $archive = class_exists(\Gaffer\Config::class) ? (array) \Gaffer\Config::get('search.archive', []) : [];
    $index   = (string) ($archive['index'] ?? 'products');
    $f = ['id', 'price', 'regular_price', 'sale_price', 'on_sale', 'stock_status', 'sku', 'rating'];
    $docs = [];
    for ($o = 0; ; $o += 100) {
        $r = $engine->search($index, ['limit' => 100, 'offset' => $o, 'attributes' => $f, 'sort' => ['id:asc']]);
        foreach ($r['results'] as $h) { $docs[$h['id']] = array_intersect_key($h, array_flip($f)); }
        if (count($r['results']) < 100) break;
    }
    file_put_contents($args[0], json_encode($docs, JSON_PRETTY_PRINT));
    echo count($docs), " documents\n";
})();
