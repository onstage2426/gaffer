<?php

/**
 * WooCommerce's notices/success.php, rendered by components/notices.twig.
 *
 * @version 8.6.0
 */

use Gaffer\Types\Notice;
use Gaffer\View;

if (!$notices) {
    return;
}

View::render("components/notices.twig", [
    "type" => "success",
    "notices" => Notice::list($notices),
]);
