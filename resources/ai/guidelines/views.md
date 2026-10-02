## Views (Twig)

- Templates live in `views/`, one subdirectory per concept (`components/`,
  `page/`, `post/`, `product/`, ...). Block templates are `@block/{name}/{name}.twig`
  (`blocks/`), ajax templates `@ajax/{Action}/x.twig` (`ajax/`).
- Render from PHP with `Gaffer\View`:
  ```php
  View::render('product/page.twig', ['post' => Post::current()]); // echoes
  $html = View::fetch('components/card.twig', $data);              // returns
  View::share('nav_primary', fn() => Menu::location('primary'));   // every template; closures run once per request
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
  the escaped `alt`. Use `fetchpriority="high"` instead of `loading="lazy"`
  above the fold. Always guard: `{% if image %}`; there is no fallback image.
- **Theme graphics** (logo, icons) are SVG files in `views/components/icons/`,
  output with `{{ source('components/icons/name.svg') }}`. Never reference
  `wp-content/uploads/` paths or attachment IDs for theme graphics.
- **Menus** are registered locations (`register_nav_menus()` in `inc/`) and
  loaded with `Menu::location('primary')`, usually shared with `View::share()`.
  Never use menu IDs. Loop safely: `{% for item in nav_primary.items ?? [] %}`.
- `|raw` only for HTML that is already trusted (WordPress content, notices);
  `doctor` counts it against `console.raw_baseline`.
- Twig gotcha: `and` binds tighter than `or`; use parentheses.

### Assets (Vite)

Sources in `assets/`, built by Vite into `public/`. Never reference files in
`public/` directly: use `Vite::tags([...])`, `Vite::url()`, `Vite::path()` or
`Vite::css_url()`. While the Vite dev server runs, only administrators get
dev-server assets; everyone else gets the build.
