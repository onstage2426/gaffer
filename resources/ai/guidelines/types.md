## Types (`Gaffer\Types\`)

Types wrap a WordPress object: raw fields are on `wp` (`post.wp.post_name`,
`term.wp.slug`); methods exist where they add something (filters, URLs,
related objects). Their methods are documented in `vendor/onstage2426/gaffer/src/Types/`; the
theme's own types and their methods are in `gaffer-reference`.

- **Factories are the only way to get one** (no `new`), with an `int` ID:
  missing, `0` or the wrong type is `null`. Cast ACF values:
  `Image::from((int) get_field('image'))`. `Post::from()` returns the mapped
  class (a theme subclass, `Image` for images); on a subclass it's `null`
  unless the post is one (`Product::from($id)`).
- **`current()` is the queried object** (the singular post or page, the term
  archive's term), `null` elsewhere. Never the loop's post: that's
  `Post::from(get_the_ID())`. `Post::query($args)` is `get_posts()` with query
  filters on (WPML/Polylang give the current language); `Post::main_query()`
  the main query's posts; `Pagination::current()` its pagination.
- **Text methods** (`title()`, `excerpt()`) return plain text: print them
  escaped, never `|raw`. HTML methods (`content()`, `Term::description()`,
  `image.attrs()`) return safe HTML: printed as they are, no `|raw`; in PHP cast
  them (`(string) $post->content()`) where a string is needed. `title()` runs `the_title` filters (wptexturize: curly
  quotes, `60x60` → `60×60`): where the exact stored text matters (SKUs,
  sizes, values sent elsewhere), use `post.wp.post_title` or a type method.
- **`meta($key)` is for PHP**: `null` when the key isn't stored. A type method
  reads the field and the template calls that method (`doctor` warns about
  `.meta()` in templates).
- **Menus:** `Menu::location('primary')` builds the tree on every request
  (~100+ ms for hundreds of items): share a big menu only where it's shown,
  and keep `object()`/`meta()` out of loops over items that aren't displayed.

**Theme subclasses:** map a post type or taxonomy to a class in `app/Types/`
with `theme.types` / `theme.terms` in `config/theme.php`; every factory then
returns it (the `gaffer-type` skill). No constructor to override: read data
lazily in methods (cached in a private property) and pass extra context
through an explicit method that returns a copy (`$product->with_variation($attributes)`). Only
`thumbnail()` may be overridden; the other `Post`/`Term` methods are `final`.
