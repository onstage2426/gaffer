<?php

declare(strict_types=1);

namespace Gaffer;

/**
 * Base class for ajax actions (ajax/{Name}/{Name}.php).
 *
 * Each action declares its own run() with typed parameters; request input is
 * mapped onto them by name (see AjaxArguments):
 *
 *     public const string METHOD = 'GET';
 *
 *     public function run(Product $product, int $quantity = 1): void
 */
abstract class AjaxAction
{
    /** HTTP method this action accepts: 'GET' or 'POST'. */
    public const string METHOD = 'POST';

    /**
     * Load WordPress with SHORTINIT: no plugins, no theme, no users. Only for
     * cheap reads ($wpdb, get_option()). Type parameters can't be used.
     */
    public const bool SHORTINIT = false;
}
