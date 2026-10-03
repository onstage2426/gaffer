## Ajax actions (`ajax/`)

One directory per action: `ajax/{Name}/{Name}.php` with class
`Theme\Ajax\{Name}\{Name}` (the namespace is fixed). Other PHP files in an
action's directory (traits, helpers) are loaded automatically before the
action; shared code lives in the directory of the action that owns it.

```php
namespace Theme\Ajax\CartAdd;

use Gaffer\AjaxAction;

class CartAdd extends AjaxAction
{
    public const string METHOD = 'POST'; // or 'GET' (default POST)

    public function run(Product $product, int $quantity = 1, string $note = ''): void
    {
        // output HTML (usually View::render('@ajax/CartAdd/x.twig', ...))
    }
}
```

- **`run()` parameters are the request keys.** No default = required. Types:
  `string`, `int`, `float`, `bool`, `array`, or a Gaffer type (`Post`, `Term`,
  `Image`, a theme subclass): the request sends an ID and `run()` receives the
  object (unknown ID or wrong type → 404). All optionally nullable. Missing or
  invalid input is a 400 before `run()` runs, so don't re-cast or sanitize
  types (`absint()`, `(int)`); still escape strings for their use.
- Bool accepts `1/0/true/false/on/off/yes/no` and empty (`false`). For nullable
  non-string types, an empty value is `null`.
- **Structured input is typed, never a JSON string:** an ID becomes a type
  parameter (`Product $product`), a set of values an `array` sent as form
  fields (`attributes[attribute_kleur]=rood` → `array $attributes`). `doctor`
  warns when `run()` decodes JSON from a string parameter.
- **An action that changes something answers with the HTML that changed**,
  and the request targets it directly (`hx-target`): one request, not an
  empty response followed by a second request to fetch the result.
- URLs: `{{ ajax_url('CartAdd') }}` in Twig, `Gaffer\Ajax::url('CartAdd')` in PHP.
- Wrong HTTP method → 405, unknown action → 404.
- No nonces (cached pages); see the core rules.

### SHORTINIT

`public const bool SHORTINIT = true;` loads WordPress without plugins, the
theme, users or pluggable functions: far cheaper, for single reads.

- **Available:** `$wpdb`, `get_option()`, Gaffer config, Twig.
- **Not available:** plugins (WooCommerce, ACF, ...), Gaffer types (so no type
  parameters), users and nonces (`current_user_can()`, `is_user_logged_in()`),
  theme functions (`ajax_url()`, `get_template_directory_uri()`).
- **Good for:** a view counter, the stock of one product ID read with `$wpdb`,
  a custom table, one option. `doctor` warns when a SHORTINIT action uses
  something that isn't loaded.
