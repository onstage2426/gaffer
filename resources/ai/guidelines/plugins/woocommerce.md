## WooCommerce

- Only loaded on full requests, never in SHORTINIT actions. Cart actions set
  cookies **before** output (WooCommerce sends its own at shutdown) and run
  WooCommerce's validation filters before changing the cart.
- **Template overrides:** `woocommerce/` holds only files named like the
  template they replace, picked by WooCommerce's own hierarchy (no
  `woocommerce.php` router, no invented names). Each stays thin: gather data,
  `View::render()`, keep what WooCommerce passes in. Variants of one page are a
  `match` in that template. Each carries the `@version` of WooCommerce's template it mirrors
  (`doctor --wp` says when WooCommerce's is newer). A thin `single-product.php` prints no Product
  structured data unless the theme does. Without `sidebar.php`, remove
  `woocommerce_get_sidebar` from `woocommerce_sidebar` (`doctor --wp` warns).
- Map `product` to a theme type for product logic; keep `WC_Product` behind
  its methods.
- WooCommerce's term ordering keeps array keys: `reset($terms)`, not `$terms[0]`.
