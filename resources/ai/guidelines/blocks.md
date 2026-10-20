## ACF blocks (`blocks/`)

One camelCase directory per block:

```
blocks/heroContent/
├── block.json         # registration
├── fields.php         # its ACF fields (optional: blocks without fields have none)
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
- Read every field once, straight into the `View::render()` array (no
  single-use variables).

`fields.php` returns the block's ACF fields as a list (conditional logic by
field name: `'field' => 'type'`, resolved to the derived key); Gaffer registers them as
a field group for this block only. Leave out `key` (Gaffer derives it from the
names: `field_hero-content__titel`, sub fields `field_..__vragen__vraag`) and
any setting that's ACF's default, except `return_format` (always set it: the
block's PHP depends on it):

```php
<?php

use Theme\Fields;

return [
    ['label' => 'Velden', 'type' => 'tab'],
    ['label' => 'Titel', 'name' => 'titel', 'type' => 'text'],
    ['label' => 'Afbeelding', 'name' => 'afbeelding', 'type' => 'image', 'return_format' => 'id'],
    ...Fields::background(),
];
```

- **Block and field names are stored in post content** (with keys derived
  from them), so renaming one loses existing values unless the content is
  migrated (see below). Tab labels aren't stored: change them freely.
  `doctor --wp` warns about content that stores fields which no longer exist,
  and `doctor` about fields `functions.php` never reads.
- **A block reads its own fields in its `functions.php`**, by name (also
  sub fields: `$row['vraag']`). When an `app/` class does the work (shared with
  a page), keep the field reading in `functions.php` and pass the values to
  the class; that keeps the block's data in one place and `doctor`'s
  "never read" check right. Don't baseline that warning.
- Fields several blocks share are static methods on `Theme\Fields`
  (`app/Fields.php`), spread into each block's list. It must not need
  WordPress (the CLI reads `fields.php` without it).
- **No `>` inside an attribute in a block's markup** (its template and what
  it includes): block output is post content, and wptexturize takes that `>`
  for the end of the tag and curls the quotes after it (`[&>svg]:size-8"` →
  `size-8″`), which silently breaks the HTML. Write `&gt;` or move it out of
  the markup (a CSS rule, an `Alpine.data()` method). `doctor` reports it.
- Block fields are never made in the ACF UI: `doctor` flags a UI/JSON field
  group that targets a block with a `fields.php`. `gaffer-reference` lists
  each block's fields.

- **No InnerBlocks and no flexible content (decided).** Blocks don't contain
  other blocks; rich text goes in a wysiwyg field. A layout with variants is
  separate blocks. `doctor` reports `<InnerBlocks>` and `fields.php` refuses
  `flexible_content`.

### Changing stored content

Block and field names, and what a field's value means, are stored in post
content. Renaming or removing a block or field, or changing a field's type,
means migrating that content with the `migrate:*` commands (dry run, then
`--run` once the user agrees): follow the `gaffer-migrate` skill. A type changes
in place only when the stored value still means the same (text → wysiwyg,
select → radio, image → file); otherwise add a field with a new name. Never
write a converter or a throwaway script that writes content.
