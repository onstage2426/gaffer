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
  structured data unless the theme does, and shows a password-protected product unless it checks
  `post_password_required()` (WooCommerce's template shows `get_the_password_form()`, which has no classes: style its field and
  button from the wrapper, or Tailwind's reset leaves them bare). Without `sidebar.php`, remove
  `woocommerce_get_sidebar` from `woocommerce_sidebar` (`doctor --wp` warns).
- **Products:** map `product` to `app/Types/Product.php` extending `Gaffer\Types\Product`
  (`product()`, `is_variable()`, `with_variation()`/`variation()`/`selected()`,
  `can_add_to_cart()`, `is_on_sale()`, `thumbnail()`, `gallery()`); the theme adds its own
  (prices, labels, attribute tables) and keeps `WC_Product` behind them. A selection is a copy:
  `{% set product = product.with_variation(attributes) %}`. Who may buy what is WooCommerce's
  `woocommerce_is_purchasable` filter, not a second method. A price per customer is WooCommerce's
  `woocommerce_product_get_price` filter too, never a price stored in the cart item (it would be
  charged after it changed). Stock or prices owned by another system (an ERP, a dealer system):
  override `confirm_purchase()` to ask it live (its docblock); Gaffer calls it at every buy step.
- WooCommerce's term ordering keeps array keys: `reset($terms)`, not `$terms[0]`.
