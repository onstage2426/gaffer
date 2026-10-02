## ACF blocks (`blocks/`)

One camelCase directory per block with three files:

```
blocks/heroContent/
├── block.json         # registration
├── functions.php      # gathers data, renders the template
└── heroContent.twig   # markup
```

`block.json`: name = `acf/` + the directory in kebab-case
(`contentFaq` → `acf/content-faq`), a real one-line description, the theme's
block category, `"acf": { "blockVersion": 3, "renderTemplate": "functions.php" }`.
**The name is stored in post content and in ACF field group location rules**,
so renaming a block later means migrating both.

`functions.php`:

```php
use Gaffer\Types\Image;
use Gaffer\View;

if (is_admin()) {
    return; // editor previews are intentionally empty
}

View::render('@block/heroContent/heroContent.twig', [
    'title' => get_field('title'),
    'image' => Image::from((int) get_field('image')),
]);
```

- Always the `is_admin()` guard first.
- Resolve objects (`Post::from()`, `Image::from()`, `Acf::field_array()`)
  before rendering; the template only presents.
- Field groups are created in the ACF UI and stored as local JSON in
  `storage/acf-json/` (keep them in sync; `doctor --wp` reports drift). The
  reference below lists each block's fields.
