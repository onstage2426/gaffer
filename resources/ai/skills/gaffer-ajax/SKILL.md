---
name: gaffer-ajax
description: Create or change an ajax action in a Gaffer theme (ajax/{Name}/{Name}.php, typed run() parameters, SHORTINIT). Use for any htmx/fetch endpoint the theme's frontend calls.
---

# Gaffer ajax action

1. Copy the closest existing action in `ajax/` to `ajax/{Name}/{Name}.php`,
   class `Theme\Ajax\{Name}\{Name}` extending `Gaffer\AjaxAction`.
2. `public const string METHOD = 'GET';` for reads, default `POST` for writes.
3. Declare the inputs as typed `run()` parameters, named like the request
   keys: `run(Product $product, int $quantity = 1)`. No default = required.
   Use a Gaffer type parameter instead of `int $id` + a lookup + a not-found
   check. Don't re-cast or sanitize types yourself.
4. Output: usually `View::render('@ajax/{Name}/x.twig', [...])` with the
   template next to the class. Set any cookies before output.
5. SHORTINIT only for a single cheap read with `$wpdb` / `get_option()` and no
   plugins, users or Gaffer types (`public const bool SHORTINIT = true;`).
6. Frontend: `{{ ajax_url('{Name}') }}` in `hx-get`/`hx-post`/`fetch`.
7. Run `php gaffer doctor` (checks the class, METHOD and the `run()` signature)
   and test the endpoint: valid input, a missing required value (400), the
   wrong method (405).
