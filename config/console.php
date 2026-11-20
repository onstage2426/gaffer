<?php

declare(strict_types=1);

return [

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
