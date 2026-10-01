<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Views Path
    |--------------------------------------------------------------------------
    |
    | Directory Twig templates are loaded from, registered under the main
    | filesystem loader namespace. Defaults to "views".
    |
    | All paths in this file are relative to the theme root (the directory
    | passed to Gaffer::boot()). Absolute paths are used as-is.
    |
    */

    // 'views' => 'views',

    /*
    |--------------------------------------------------------------------------
    | Extra Twig Namespaces
    |--------------------------------------------------------------------------
    |
    | Additional namespace => path pairs registered on the Twig filesystem
    | loader alongside the main views path, so templates colocated with
    | blocks/ajax actions can be referenced as {% include '@block/...' %}.
    |
    */

    // 'view_namespaces' => [
    //     'block' => 'blocks',
    //     'ajax' => 'ajax',
    // ],

    /*
    |--------------------------------------------------------------------------
    | Includes Path
    |--------------------------------------------------------------------------
    |
    | Directory of plain PHP files auto-included on boot (flat files, plus
    | one level of subdirectories). Defaults to "inc".
    |
    */

    // 'includes' => 'inc',

    /*
    |--------------------------------------------------------------------------
    | Storage Path
    |--------------------------------------------------------------------------
    |
    | Writable runtime directory used for ACF JSON sync ("acf-json"),
    | compiled Twig cache ("cache/views"), and logs. Defaults to "storage".
    |
    */

    // 'storage' => 'storage',

    /*
    |--------------------------------------------------------------------------
    | Blocks Path
    |--------------------------------------------------------------------------
    |
    | Directory scanned for "*block.json" files, each registered as a block
    | type on boot. Defaults to "blocks".
    |
    */

    // 'blocks' => 'blocks',

    /*
    |--------------------------------------------------------------------------
    | Ajax Path
    |--------------------------------------------------------------------------
    |
    | Directory Ajax::boot() dispatches actions from, expecting each action
    | at "{action}/{action}.php". Defaults to "ajax".
    |
    */

    // 'ajax' => 'ajax',

    /*
    |--------------------------------------------------------------------------
    | Public Path
    |--------------------------------------------------------------------------
    |
    | Directory Vite's build output (and manifest.json/hotfile) is read
    | from. Defaults to "public".
    |
    */

    // 'public' => 'public',

];
