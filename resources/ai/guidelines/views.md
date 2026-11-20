## Views (Twig)

### How a page is built

- A template file (`page.php`, `archive.php`, `woocommerce/*.php`) gathers the
  data, then `get_header()`, `View::render('{concept}/page.twig', [...])`,
  `get_footer()`. `header.php`/`footer.php` are the document shell in PHP
  (`<html>`, `wp_head()`, `<body>`, `wp_footer()`); no other markup in PHP
  (hooks and shortcodes return `View::fetch()`).
- Twig templates are fragments: no `{% extends %}`, `<html>` or `<body>`.
  Except documents that aren't pages: an e-mail body or PDF source is a whole
  document with inline styles, rendered with `View::fetch()`.
- `views/{concept}/page.twig` (one item), `{concept}/archive.twig` (a
  listing), `{concept}/card.twig` (one item in a list); `views/components/`
  for parts used across the site. Block templates (`@block/{name}/{name}.twig`)
  belong to their block: markup a page shares with a block is a component both
  include.
- **Partials get exactly what they need:**
  `{{ include('components/button/primary.twig', { link: primary }, with_context = false) }}`,
  always the `include()` function, never the `{% include %}` tag.
  `gaffer-reference` lists each template's variables; `php gaffer twig:trace <template>`
  shows who renders or includes it and what it includes.

### Rendering

```php
View::render('product/page.twig', ['post' => Post::current()]); // echoes
$html = View::fetch('components/card.twig', $data);              // returns
View::share('nav_primary', fn() => Menu::location('primary'));   // page renders (not ajax); runs once per request
```

A render's own data wins over shared data with the same key (a `null` by
mistake hides the shared value). Skip a costly shared value where it isn't
shown, inside the closure: `fn() => is_checkout() ? null : Menu::location('mega')`.
Write template names literally in PHP, so `doctor` can follow them.

### Decided

- **Templates get all content from PHP.** No lookup functions in Twig; Gaffer's
  Twig functions are `config(key)` and `ajax_url(action)`, the theme's are in
  `gaffer-reference`. Passing a theme object with display methods
  (`offer.total()`) is fine: a template may not fetch content itself.
- **Theme strings:** `{{ 'Read more'|t }}` is gettext in the theme's text
  domain (`style.css`'s `Text Domain`); in PHP `__('Read more', '<domain>')`.
  Only on a literal string: `{{ open ? 'Close'|t : 'Open'|t }}`, and
  placeholders after translating: `{{ '%d results'|t|format(count) }}`.
  Content (ACF values, titles) is translated as content, never with `|t`.
- **Images:** write the `<img>` tag; the type fills in the inside:
  `<img class="w-full object-cover" {{ image.attrs('large') }} loading="lazy">`
  inside `{% if image %}` (no fallback image). `attrs()` gives `src`,
  `srcset`, `sizes`, the size's `width`/`height` and the `alt`: never an `alt`
  or `width`/`height` next to it (size it with CSS). `decorative: true` for an
  image beside text that says the same; a box cropped with `object-cover`
  needs its real width as `sizes` (`attrs('large', '(min-width: 1024px) 60vw, 150vw')`)
  or a registered size of the box's shape, never `src('full')`. Keep
  WordPress's `1536x1536` and `2048x2048` sizes. `fetchpriority="high"`
  instead of `loading="lazy"` above the fold.
- **Theme graphics** are SVGs in `views/components/icons/`:
  `{{ source('components/icons/logo.svg') }}`. An icon sized in templates is a
  partial with the SVG inline and `class="{{ class ?? 'size-4' }}"` on its
  root, included with a `class`; never size a `source()`d SVG through its
  parent (`*:size-*` sizes every child). Never `wp-content/uploads/` paths or
  attachment IDs for theme graphics.
- **Menus** are registered locations: `Menu::location('primary')`, never menu
  IDs; loop with `{% for item in nav_primary.items ?? [] %}`.
- **`|raw` only for trusted HTML** (WordPress content, plugin output), with
  where it comes from on the line or the line above:
  `{{ post.content()|raw }}{# trusted: WordPress content #}`. The same for
  `new Markup()` in PHP (`// trusted: …`); prefer `|raw` in the template.

### Pitfalls (`doctor` reports most of them)

- Forms don't nest: a template with a `<form>` can't be included inside another form.
- Alpine: a string or array `:class` removes only what it added, so a class in
  both `class` and `:class` stays on; keep the changing classes in `:class`
  only, or use the object syntax (`:class="{ hidden: !open }"`). Scope `hover:`
  to the inactive state next to a state variant. Directives (and `$store`)
  need an `x-data` on or above the element.
- Autoescape escapes values, not string literals: `{{ error ? 'aria-invalid="true"' }}`
  prints its quotes as they are.
- `and` binds tighter than `or`: use parentheses.
