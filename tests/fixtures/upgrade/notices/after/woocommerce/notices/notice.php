<?php

/**
 * WooCommerce's notices/notice.php (info notices), rendered by components/notices.twig.
 *
 * @version 10.4.0
 */

use Gaffer\Types\Notice;
use Gaffer\View;

if (!$notices) {
    return;
}

View::render("components/notices.twig", [
    "type" => "notice",
    "notices" => Notice::list($notices),
]);
