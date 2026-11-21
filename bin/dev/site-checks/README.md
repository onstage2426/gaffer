# Site checks (maintainer tools)

Browser checks of a site's htmx/Alpine interactions through the real web server: what
`doctor --wp` can't see (it renders pages but never clicks). They found what static checks
missed on 2026-11-20: an `<input name="form">` that shadowed `HTMLFormElement.form` and stopped
every Gaffer form under htmx 4.

Playwright comes from a theme that has it (the starter has it): the first argument is
that theme's directory, only used to resolve `playwright`.

```sh
P=/path/to/a/theme   # with playwright installed; the sites on this host: local/AGENTS_LOCAL.md
node bin/dev/site-checks/htmx.mjs $P https://shop.test /a-page-with-a-form/   # shop add-to-cart, archive, a Gaffer form, an error answer
node bin/dev/site-checks/drawer-select.mjs $P https://shop.test               # drawer with the quantity <select> (submit-qty)
node bin/dev/site-checks/drawer-buttons.mjs $P https://shop.test              # drawer with +/− buttons and a remove button
node bin/dev/site-checks/variation.mjs $P https://shop.test/product/<variable>/ select  # or: link
node bin/dev/site-checks/search.mjs $P https://shop.test /winkel/ ma          # searchbar and archive through search.attrs()
```

- Console noise from Turnstile's iframe (`data:,` CSP (filtered), `%c` lines, error 600010 in headless
  Chromium) is filtered or expected; headless Chromium gets no Turnstile token, so a form takes
  the spam path (`X-Gaffer-Spam: turnstile-missing`) where Turnstile is on.
- **A form check on a site without Turnstile creates a real Gravity Forms entry** (Turnstile is
  on only with `turnstile.site_key` in `config/turnstile.php` *and* the secret: the starter keeps
  it off) (and maybe a
  notification mail): note the highest entry ID first and delete newer test entries by ID.
  Test submissions also land in `storage/logs/spam.log`; remove them, or `doctor --wp`'s
  "many submissions without a Turnstile answer" fires.
- `htmx4-convert.py <theme>`: the mechanical htmx 2 → 4 changes used on 2026-11-20 (renames,
  `hx-on` event names, a form wrapper's `hx-target="this"` moved onto the `<form>`).
