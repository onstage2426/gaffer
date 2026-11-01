---
name: gaffer-ajax
description: Create or change an ajax action in a Gaffer theme (app/Ajax/{Name}/{Name}.php, typed run() parameters, SHORTINIT). Use for any htmx/fetch endpoint the theme's frontend calls.
---

# Gaffer ajax action

1. Copy the closest existing action in `app/Ajax/` to `app/Ajax/{Name}/{Name}.php`,
   class `Theme\Ajax\{Name}\{Name}` extending `Gaffer\AjaxAction`.
2. `public const string METHOD = 'GET';` for reads, default `POST` for writes.
3. Declare the inputs as typed `run()` parameters, named like the request
   keys: `run(Product $product, int $quantity = 1)`. No default = required.
   Use a Gaffer type parameter instead of `int $id` + a lookup + a not-found
   check. Don't re-cast or sanitize types yourself.
4. Output: usually `View::render('{concept}/x.twig', [...])` with the template
   in `views/` (like any template). Set any cookies before output.
5. SHORTINIT only for a single cheap read with `$wpdb` / `get_option()` and no
   plugins, users or Gaffer types (`public const bool SHORTINIT = true;`).
6. Frontend: `{{ ajax_url('{Name}') }}` in `hx-get`/`hx-post`/`fetch`.
7. Run `php gaffer doctor` (checks the class, METHOD and the `run()` signature)
   and test the endpoint with `php gaffer ajax:call` (below): valid input, a
   missing required value (400).

## Testing actions: `ajax:call`

`php gaffer ajax:call <Action> key=value ... [--session=<name>] [--select=<css>] [--json]`
calls the action over HTTP the way htmx does (the action's own method,
`HX-Request`), and prints the status, the `HX-*`/`X-Gaffer-*`/`Location`
headers and the HTML (or only `--select`'s elements). Array keys work:
`"attributes[pa_size]=xl"`, `"fields[email]=a@b.nl"`.

- `--session=<name>` keeps the visitor's cookies between calls, so a sequence
  runs as one visitor: `CartAdd product=65 quantity=2`, then `CartDrawer`,
  `CartUpdate`, `CartRemove`, each with the same `--session`. No throwaway
  scripts with your own cookie handling.
- It writes what the action writes (a cart, a WooCommerce session, a form entry
  and its e-mails). Always finish with `php gaffer ajax:end <name>`: it deletes
  the session's WooCommerce session and cart and forgets its cookies.
- To see the result in the page: `php gaffer render:screenshot <path>
  --session=<name>` (the session's cart in the render and in the browser's
  ajax calls, e.g. with `--click` on the cart button).
- Forms: on a site with Turnstile a CLI submission takes the spam path (the
  normal answer, plus `x-gaffer-spam: turnstile` outside production): that's
  expected, not a bug.
