<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | |raw Baseline
    |--------------------------------------------------------------------------
    |
    | Number of |raw uses in templates plus new Markup(...) in theme PHP (both
    | mark HTML as trusted) that are known and accepted. `doctor` warns (and
    | lists them all) when there are more, so new ones get looked at. Unset:
    | doctor just reports the count.
    |
    */

    // 'raw_baseline' => 0,

    /*
    |--------------------------------------------------------------------------
    | render:diff Ignores
    |--------------------------------------------------------------------------
    |
    | Regular expressions (with delimiters) render:diff always removes before
    | comparing, ahead of any --ignore: what changes on every request on this
    | site (a plugin's tokens or random labels, a time-based value). They match
    | the page as rendered: mind single and double quotes. For Gravity Forms'
    | own markup, e.g.:
    | "/name='(?:gform_currency|state_\\d+)'[^>]*value='[^']*'/"
    |
    */

    // 'render_ignore' => [],

];
