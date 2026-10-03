## WooCommerce

- WooCommerce is only loaded on full requests: never in SHORTINIT ajax actions.
- Ajax actions that change the cart run with the cart loaded (`WC()->cart`).
  Set cookies **before** echoing output: WooCommerce updates its own cookies
  at shutdown, when headers are already sent.
- Run WooCommerce's validation filters before changing the cart
  (`woocommerce_add_to_cart_validation`, `woocommerce_update_cart_validation`).
- **Template overrides:** `woocommerce/` holds only files named like the
  WooCommerce template they replace (`single-product.php`,
  `archive-product.php`, `taxonomy-product_cat.php`, `notices/success.php`).
  Let WooCommerce's own hierarchy pick them: no `woocommerce.php` router and
  no invented template names. Each override stays thin: gather data, call
  `View::render()`, keep using what WooCommerce passes in (`$notices`,
  `global $product`). Variants of one page (shop vs search) are a `match` in
  that template, not extra files. `doctor --wp` reports files WooCommerce
  doesn't have a template for, and a `woocommerce.php`.
- Map `product` to a theme type in `theme.types` for product logic (prices,
  variations); keep `WC_Product` behind methods on that type.
- Watch out: WooCommerce's term ordering keeps array keys, so take the first
  term with `reset($terms)`, not `$terms[0]`.
