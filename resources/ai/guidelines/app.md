## Theme classes (`app/`)

`app/` is the autoloaded `Theme\` namespace: types (`Theme\Types\`), Twig
extensions (`Theme\Twig\`) and the logic hooks, blocks and ajax actions call
(`Theme\Cart`, `Theme\Fields`). One class per concept, `final` unless meant to
be extended, static methods for stateless helpers.

- snake_case methods, explicit types, `null` for missing data (no `0.0` or
  `''` as "none"). Only methods implementing a library's interface keep its
  naming, marked `#[\Override]`.
- **Twig extensions only shape values for display** (formatting, a choice to
  a class, a URL helper), registered in `theme.twig_extensions`; never fetch
  content, run shortcodes or call plugins. The first docblock line is what
  `gaffer-reference` shows.
- **Templates use the types, not what's behind them:** no `.get_*()` or
  `.meta()` in Twig; add a method to the type (`label()`) and call that.
