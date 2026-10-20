## Types (`Gaffer\Types\`)

Types wrap a WordPress object. Raw fields are on `wp` (`$post->wp->post_name`,
`post.wp.post_name`, `term.wp.slug`); methods exist only where they add
something (filters, URLs, related objects). `Post`, `Term` and `MenuItem`
have `id()`, `title()` and `link()`.

Text methods (`title()`, `excerpt()`) return plain text with WordPress's
entities decoded: print them escaped (`{{ post.title() }}`), never `|raw`. HTML
methods (`content()`, `Term::description()`) are printed with `|raw`.

`Post::title()` is the title as WordPress displays it: it runs the
`the_title` filters, so wptexturize changes the text (curly quotes, dashes,
`60x60` → `60×60`). Where the exact stored text matters (product names and
sizes, SKUs, values compared or sent elsewhere), use `post.wp.post_title` (or
a type method that returns it, e.g. a `Product::name()`).

**Factories are the only way to get one** (no `new`). They take an `int` ID;
missing, `0` or the wrong type → `null`. Cast ACF values: `(int) get_field('x')`.

| Factory | Returns |
|---|---|
| `Post::from(int $id)` | The post as its mapped class (a theme subclass for mapped post types, `Image`/`Attachment` for media). On a subclass, `null` unless it is one: `Product::from($id)`. |
| `Post::current()` | The current post (loop / singular). |
| `Post::query(array $args)` | `get_posts($args)` as types, with query filters on (`suppress_filters` is `false` unless you pass `true`): WPML/Polylang return the current language. |
| `Post::main_query()` | The main query's posts (archives, search). |
| `Term::from(int $id)`, `Term::current()`, `Term::query(array $args)` | The same for terms (`current()` = queried term). |
| `Image::from(int $id)`, `Attachment::from(int $id)` | Media. `Image::from()` is `null` for non-images. |
| `Menu::location(string $location)` | The menu tree for a registered location. |
| `Pagination::current()` | Main query pagination: readonly `page`, `total_pages`, `items`, `total_items`, `per_page`, `results_start`, `results_end`; `pages(padding)` (page → URL, `null` = ellipsis), `previous()`, `next()`, `previous_link()`, `next_link()`. |

Main methods:
- `Post`: `content()`, `excerpt()`, `date(?format)`, `modified_date(?format)`,
  `parent()`, `ancestors()` (nearest first: the top-level one is
  `array_last()`), `children()` (page order, then title), `terms($taxonomy)`,
  `blocks()`, `meta($key)`, `thumbnail()`, `is_current()`.
- `Term`: `description()`, `parent()`, `ancestors()`, `children()` (direct
  children, empty ones too, by name or WooCommerce's category order), `meta($key)`, `thumbnail()`.
- `Image`: `attrs(size, sizes)` (`sizes` optional, see Views), `src(size)`, `path(size)` (the file on disk `src()` serves, for PDFs and image processing; never turn a URL into a path yourself), `alt()`, `width()`, `height()` (the
  full image's; `null` when WordPress doesn't know). `Attachment`: `url()`, `mime()`.
- `MenuItem`: `title()`, `link()`, `target()` (`null` unless set), `classes()`, `is_current()`,
  `is_current_ancestor()`, `children()`, `has_children()`, `is_external()`,
  `object()` (the linked `Post`/`Term` as its type, e.g. a category's
  `thumbnail()`; `null` for custom links and archives), `meta($key)` (the
  item's own fields, e.g. an ACF icon: `Image::from((int) $item->meta('icon'))`
  in PHP). Large menus are slow: `Menu::location()` takes ~100+ ms for
  hundreds of items, every request. Share a big menu only where it's shown,
  and keep `object()`/`meta()` out of loops over all items when only the
  top level is displayed.

**Theme subclasses:** map a post type or taxonomy to a class in `app/Types/`
via `theme.types` / `theme.terms` in `config/theme.php`; every factory then
returns that class. There is no constructor or `build()` to override: read
WordPress data lazily in methods (cache in a private property) and pass extra
context through an explicit method (e.g. `$product->select_variation($attributes)`),
never through a factory argument. The theme's mapped classes and their
methods are in `gaffer-reference`.
