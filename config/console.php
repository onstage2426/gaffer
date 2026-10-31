<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | |raw Baseline (deprecated)
    |--------------------------------------------------------------------------
    |
    | Deprecated: mark each |raw (templates) and new Markup(...) (theme PHP)
    | with a trust comment where it's used instead, {# trusted: why #} or
    | // trusted: why, and remove this key. While it's set, doctor still
    | counts the unmarked uses against it (and warns that it's deprecated).
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
