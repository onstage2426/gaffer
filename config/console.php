<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Site URL
    |--------------------------------------------------------------------------
    |
    | URL the `gaffer` CLI pretends to be requesting when a command loads
    | WordPress (host, https). Override per run with --url. Defaults to
    | http://localhost, which is fine unless URLs or the site host matter.
    |
    */

    // 'url' => 'https://example.test',

    /*
    |--------------------------------------------------------------------------
    | |raw Baseline
    |--------------------------------------------------------------------------
    |
    | Number of |raw uses in templates that are known and accepted. `doctor`
    | warns (and lists them all) when there are more, so new ones get looked
    | at. Unset: doctor just reports the count.
    |
    */

    // 'raw_baseline' => 0,

];
