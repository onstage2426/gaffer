## Views (Twig)

### How a page is built

- A WordPress template file (`page.php`, `archive.php`, `404.php`,
  `woocommerce/*.php`) gathers the data, then: `get_header()`,
  `View::render('{concept}/page.twig', [...])`, `get_footer()`.
- The document shell is `header.php` / `footer.php`, in PHP on purpose
  (`<html>`, `wp_head()`, `<body>`, `wp_footer()`: WordPress and plugins hook
  into it). Apart from the shell they only gather data and render components:
  no other markup in PHP.
- Twig templates are fragments between those: never `{% extends %}`,
  `<html>` or `<body>` in Twig.

### Where templates go

- `views/{concept}/page.twig`: one item (`page/`, `post/`, `product/`);
  `{concept}/archive.twig`: a listing; `{concept}/card.twig`: one item in a
  list. `views/components/`: parts used across the site (header, footer,
  buttons, `modals/`, `icons/`).
- Block templates are `@block/{name}/{name}.twig` (`blocks/`) and belong to
  their block; ajax templates `@ajax/{Action}/x.twig` (`ajax/`). When a page
  needs the same markup as a block, move it to `components/` and include it
  from both, so changing the block can't break the page.
- **Partials get exactly what they need**, never the caller's variables:
  ```twig
  {{ include('components/button/primary.twig', { link: primary }, with_context = false) }}
  ```
  Always the `include()` function, never the `{% include %}` tag. The
  reference below lists each template's variables (`(optional)`: read behind
  `??`, `|default` or `is defined`).
- `doctor` reports templates that are rendered or included but don't exist,
  templates nothing uses, includes without `with_context = false`, and HTML
  in PHP outside `header.php`/`footer.php` (filters and shortcodes that
  return HTML use `View::fetch()`).

### Rendering

- Render from PHP with `Gaffer\View`:
  ```php
  View::render('product/page.twig', ['post' => Post::current()]); // echoes
  $html = View::fetch('components/card.twig', $data);              // returns
  View::share('nav_primary', fn() => Menu::location('primary'));   // page templates (not ajax); closures run once per request
  ```
- **Templates get all content from PHP.** There are no lookup functions in
  Twig (`get_post()` etc. don't exist). Gaffer's Twig functions are `config(key)`
  and `ajax_url(action)`; the theme's are listed in the reference below.
- **Images:** write the `<img>` tag yourself and let the type fill in the
  inside, with no `|raw`:
  ```twig
  <img class="w-full object-cover" {{ image.attrs('large') }} loading="lazy">
  ```
  `attrs(size)` gives `src`, `srcset`, `sizes`, `width`/`height` of that size and
  the escaped `alt`. An image cropped into a box with `object-cover` (a box
  of another aspect ratio) is wider than the box, so give the width it needs
  as `sizes`: `image.attrs('large', '(min-width: 1024px) 60vw, 150vw')`
  (box height × the image's aspect ratio). Not `src('full')`: that loads the
  full file on every screen. Use `fetchpriority="high"` instead of `loading="lazy"`
  above the fold. Always guard: `{% if image %}`; there is no fallback image.
- **Theme graphics** (logo, icons) are SVG files in `views/components/icons/`,
  output with `{{ source('components/icons/name.svg') }}`. Never reference
  `wp-content/uploads/` paths or attachment IDs for theme graphics.
- **Menus** are registered locations (`register_nav_menus()` in `inc/`) and
  loaded with `Menu::location('primary')`, usually shared with `View::share()`.
  Never use menu IDs. Loop safely: `{% for item in nav_primary.items ?? [] %}`.
- `|raw` only for HTML that is already trusted (WordPress content, notices,
  plugin output like Yoast breadcrumbs or a Gravity Forms shortcode): pass it
  to the template as a string and print it with `|raw` there. `doctor` counts
  `|raw` and `new Markup(...)` in theme PHP together against
  `console.raw_baseline`, so wrapping HTML in `Twig\Markup` in PHP doesn't
  hide it; prefer `|raw` in the template, where the trust is visible.
- Twig gotcha: `and` binds tighter than `or`; use parentheses.
