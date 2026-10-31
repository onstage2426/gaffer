## Theme classes (`app/`)

`app/` is the `Theme\` namespace (autoloaded): the theme's PHP that isn't a
template file, block, ajax action or hook registration.

- **What goes here:** types (`Theme\Types\`, see Types), Twig extensions
  (`Theme\Twig\`), and the logic hooks, blocks and ajax actions call
  (`Theme\Cart`, `Theme\Sitemap`, `Theme\Fields`). One class per concept,
  `final` unless it's meant to be extended; static methods for stateless helpers.
- **snake_case method names**, explicit types, `null` for missing data (no
  magic values like `0.0` or `''`). Only methods implementing a library's
  interface keep its naming, marked `#[\Override]`.
- **Twig extensions only shape values for display:** formatting, mapping a
  choice to a class (`background()`), a URL helper. Never fetch content, run
  shortcodes or call plugins from Twig: that data is prepared in PHP and passed
  in. Register the class in `theme.twig_extensions`; the first line of each
  method's docblock is what the reference shows.
- **Templates use the types, not what's behind them:** no `post.product().get_title()`,
  `product.get_meta('label')` or `term.meta('tint')` in Twig (field names stay
  in PHP). Add a method to the type (`label()`, `tint()`) and call that.
- `doctor` reports method names that aren't snake_case, Twig functions and
  filters that look content up (WordPress/ACF/plugin lookups, type factories),
  and `.get_*()` and `.meta()` calls in templates (Gaffer's types have no `get_` methods, so those reach into
  WordPress, WooCommerce or a plugin).
