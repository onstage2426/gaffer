## Ajax actions (`app/Ajax/`)

One directory per action, `app/Ajax/{Name}/{Name}.php`, class
`Theme\Ajax\{Name}\{Name}` (the `gaffer-ajax` skill). Classes only that action
uses go in its directory; templates in `views/` by concept. Actions answer
with a fragment: their renders get no `View::share()` data, pass what they show.

```php
class CartAdd extends AjaxAction
{
    public const string METHOD = 'POST'; // or 'GET'

    public function run(Product $product, int $quantity = 1, string $note = ''): void
    {
        View::render('cart/added.twig', [...]);
    }
}
```

- **`run()` parameters are the request keys**, no default = required:
  `string`, `int`, `float`, `bool`, `array`, or a Gaffer type (the request
  sends an ID, `run()` gets the object; unknown → 404), optionally nullable.
  Invalid input is a 400 before `run()`: don't re-cast or sanitize types;
  still escape strings for their use.
- **Structured input is typed, never a JSON string:** an ID is a type
  parameter, a set of values an `array` of form fields
  (`attributes[kleur]=rood`).
- **An action that changes something answers with the HTML that changed**,
  targeted directly (`hx-target`): no empty answer followed by a refetch.
- URLs: `{{ ajax_url('CartAdd') }}`, `Gaffer\Ajax::url('CartAdd')` in PHP.
  Wrong method → 405, unknown action → 404, a body over `post_max_size` → 413.
- **`SHORTINIT = true`** loads WordPress without plugins, the theme, users or
  pluggable functions: for single cheap reads (`$wpdb`, `get_option()`, config,
  Twig), never with Gaffer types, WooCommerce or `current_user_can()`
  (`doctor` warns).
- Test with `php gaffer ajax:call`, not throwaway scripts.
