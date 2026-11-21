## Assets (`assets/`, Vite)

Sources in `assets/`, built by Vite into `public/` (never edit or link its
files by hand). Load entries with `Vite::tags(['assets/css/app.css', 'assets/js/app.js'])`
or one URL with `Vite::url()`; the stylesheet is its own entry. While the dev
server runs, only administrators get its assets.

- `assets/js/` entries and shared setup, `assets/js/components/` one module
  per front-end component (registered in one place), `assets/css/`,
  `assets/font/`.
- **htmx 4** (`htmx.org` ^4 in `package.json`; `import htmx from "htmx.org"` sets
  `window.htmx`; with Alpine also `import "htmx.org/dist/ext/hx-alpine-compat.js"`). Nothing
  inherits by itself: put `hx-target`/`hx-swap`/`hx-indicator` on the element that makes the
  request (or write `hx-target:inherited`). Events are colon-separated (`hx-on::before:request`,
  `htmx:after:swap`); 4xx/5xx answers swap unless they say `HX-Reswap: none` (Gaffer's do);
  `hx-get` sends only its own form's inputs. `doctor` reports htmx 2 names.
- **Modules import what they use.** A global on `window` only for what HTML
  attributes must reach (an htmx filter, `hx-on`), with a comment saying which.
- **Styling is utility classes in the templates.** Custom CSS only for markup
  the theme doesn't write, library state classes (`[x-cloak]`,
  `.htmx-request`) and `@keyframes`/`@utility`. Colours, fonts and sizes come
  from the theme tokens (`@theme`), never hardcoded, also not in `style=""`;
  except e-mail and PDF sources, which get inline plain values.
- Files that need a stable public URL (images in sent e-mails) get their own
  folder, let through by the theme's `.htaccess` next to `public/assets/`.
- `vite.config.js` uses Gaffer's plugin (`import gaffer from
  "./vendor/onstage2426/gaffer/resources/vite.js"`, `plugins: [gaffer(), ...]`):
  it sets what Gaffer reads (`public/`, the manifest) and the `@gaffer` alias for
  Gaffer's JavaScript; don't set those by hand. No dev server: a change shows
  after a build (`npm run build`, or `npm run start` to rebuild on save).
- **`npm run build`** after changing classes in a template too (Tailwind only
  has a utility after a build) and before deploying; then clear a page cache
  (a cached page links the previous build's files), and after a `composer
  update` that changed Gaffer's JavaScript. `doctor` reports a stale build
  (also older Gaffer JavaScript) and classes the built CSS lacks.
