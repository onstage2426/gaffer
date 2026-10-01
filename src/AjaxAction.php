<?php

declare(strict_types=1);

namespace Gaffer;

/**
 * Base class for ajax actions (ajax/{Name}/{Name}.php).
 *
 * Each action declares its own run() with typed parameters; request input is
 * mapped onto them by name (see AjaxArguments):
 *
 *     public function run(int $product_id, int $quantity = 1): void
 */
abstract class AjaxAction
{
    /** HTTP method this action accepts: 'GET' or 'POST'. */
    public string $method = 'POST';

    /** Load WordPress with SHORTINIT (no plugins, no theme). */
    public bool $shortinit = false;
}
