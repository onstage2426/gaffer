## Assets (`assets/`, Vite)

Sources in `assets/`, built by Vite into `public/` (build output: never edit
it by hand or reference its files directly). Load them with
`Vite::tags([...])`, `Vite::url()`, `Vite::path()` or `Vite::css_url()`; the
names are the entries in `vite.config.js` (`assets/js/app.js`). While the dev
server runs (`public/.vite/hotfile`), only administrators get dev-server
assets; everyone else gets the build.

- **Layout:** `assets/js/` holds the entries (`app.js`, `app-admin.js`) and
  shared setup; `assets/js/components/` one module per front-end component
  (an Alpine component, a slider), registered in one place; `assets/css/`
  (the entry CSS imports the rest), `assets/font/` (self-hosted fonts).
- **Modules import what they use.** No libraries on `window` for other files
  to pick up. A global is only for something HTML attributes must reach
  (an htmx trigger filter, `hx-on`); give it a comment saying which.
- **Styling is utility classes in the templates.** Custom CSS only for markup
  the theme doesn't write (plugin output, generated markup), state classes set
  by libraries (`[x-cloak]`, `.htmx-request`), and `@keyframes`/`@utility`.
  Colours, fonts and sizes come from the theme tokens (`@theme`), never
  hardcoded, also not in a `style=""`.
- **Environment-specific settings** (dev server origin, URLs) come from an
  env file (`.env`, not committed), never from `vite.config.js` itself.
- Run `npm run build` before deploying. `doctor` reports a missing or
  stale build (older than `assets/`) and `Vite::` calls for entries the
  manifest doesn't have.
