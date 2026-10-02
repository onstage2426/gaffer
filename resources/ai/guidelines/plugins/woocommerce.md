## WooCommerce

- WooCommerce is only loaded on full requests: never in SHORTINIT ajax actions.
- Ajax actions that change the cart run with the cart loaded (`WC()->cart`).
  Set cookies **before** echoing output: WooCommerce updates its own cookies
  at shutdown, when headers are already sent.
- Run WooCommerce's validation filters before changing the cart
  (`woocommerce_add_to_cart_validation`, `woocommerce_update_cart_validation`).
- Template overrides in `woocommerce/` stay thin: gather data, `View::render()`.
- Map `product` to a theme type in `theme.types` for product logic (prices,
  variations); keep `WC_Product` behind methods on that type.
- Watch out: WooCommerce's term ordering keeps array keys, so take the first
  term with `reset($terms)`, not `$terms[0]`.
