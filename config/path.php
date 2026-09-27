<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Views Path
    |--------------------------------------------------------------------------
    |
    | Directory Twig templates are loaded from, registered under the main
    | filesystem loader namespace. Defaults to "views" under the theme root
    | (BS_TEMPLATE_DIR if bootstrap.php defines it, else get_template_directory()).
    |
    */

    // 'views' => BS_TEMPLATE_DIR . '/views',

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
    //     'block' => BS_TEMPLATE_DIR . '/blocks',
    //     'ajax' => BS_TEMPLATE_DIR . '/ajax',
    // ],

    /*
    |--------------------------------------------------------------------------
    | Includes Path
    |--------------------------------------------------------------------------
    |
    | Directory of plain PHP files auto-included on boot (flat files, plus
    | one level of subdirectories). Defaults to "inc" under the theme root.
    |
    */

    // 'includes' => BS_TEMPLATE_DIR . '/inc',

    /*
    |--------------------------------------------------------------------------
    | Storage Path
    |--------------------------------------------------------------------------
    |
    | Writable runtime directory used for ACF JSON sync ("acf-json"),
    | compiled Twig cache ("cache/views"), and logs. Defaults to "storage"
    | under the theme root.
    |
    */

    // 'storage' => BS_TEMPLATE_DIR . '/storage',

    /*
    |--------------------------------------------------------------------------
    | Blocks Path
    |--------------------------------------------------------------------------
    |
    | Directory scanned for "*block.json" files, each registered as a block
    | type on boot. Defaults to "blocks" under the theme root.
    |
    */

    // 'blocks' => BS_TEMPLATE_DIR . '/blocks',

    /*
    |--------------------------------------------------------------------------
    | Ajax Path
    |--------------------------------------------------------------------------
    |
    | Directory Ajax::boot() dispatches actions from, expecting each action
    | at "{action}/{action}.php". Defaults to "ajax" under the theme root.
    |
    */

    // 'ajax' => BS_TEMPLATE_DIR . '/ajax',

    /*
    |--------------------------------------------------------------------------
    | Public Path
    |--------------------------------------------------------------------------
    |
    | Directory Vite's build output (and manifest.json/hotfile) is read
    | from. Defaults to "public" under the theme root.
    |
    */

    // 'public' => BS_TEMPLATE_DIR . '/public',

];
