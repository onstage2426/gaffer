<?php

/**
 * WooCommerce's notices/notice.php (info notices), rendered by components/notices.twig.
 *
 * @version 10.4.0
 */

use Gaffer\View;

if (!$notices) {
    return;
}

View::render("components/notices.twig", [
    "type" => "notice",
    // The data attributes WooCommerce's checkout script reads (the field a notice is about)
    "notices" => array_map(fn(array $notice): array => ["text" => wc_kses_notice($notice["notice"]), "attrs" => wc_get_notice_data_attr($notice)], $notices),
]);
