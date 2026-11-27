<?php


/* Frontend - remove WooCommerce body classes */
remove_filter("body_class", "wc_body_class");

/* Shop - no theme-compat sidebar (WordPress's page list and empty widgets) under shop pages */
remove_action("woocommerce_sidebar", "woocommerce_get_sidebar");

/* Product page - the theme's own gallery and variation form replace WooCommerce's single-product script */
add_action("wp_enqueue_scripts", function (): void {
    if (is_product()) {
        wp_dequeue_script("wc-single-product");
    }
}, 999);
