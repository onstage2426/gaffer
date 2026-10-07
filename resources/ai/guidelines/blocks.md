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

`fields.php` returns the block's ACF fields as a list; Gaffer registers them as
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
- Fields several blocks share are static methods on `Theme\Fields`
  (`app/Fields.php`), spread into each block's list. It must not need
  WordPress (the CLI reads `fields.php` without it).
- Block fields are never made in the ACF UI: `doctor` flags a UI/JSON field
  group that targets a block with a `fields.php`. The reference below lists
  each block's fields.

- **No InnerBlocks and no flexible content (decided).** Blocks don't contain
  other blocks; rich text goes in a wysiwyg field. A layout with variants is
  separate blocks. `doctor` reports `<InnerBlocks>` and `fields.php` refuses
  `flexible_content`.

### Changing a field's type

ACF stores values without their type, and nothing converts them:

- **In place** only when the stored value still means the same: text →
  textarea or wysiwyg, select → radio or button group, image → file (both an
  ID). Check how the block's PHP reads it.
- **Otherwise add a field with a new name** and stop reading the old one.
  `doctor --wp` then lists the pages that still store the old field: editors
  fill in the new one, then `migrate:remove-field` deletes the old values.
  Never write a converter.

### Renaming or removing a block or field

Change the code first, then migrate the stored content:

```
php gaffer migrate:field acf/content-faq titel kop                 # rename a field
php gaffer migrate:field acf/content-faq vragen.vraag vraag_tekst  # rename a sub field (repeater/group)
php gaffer migrate:block acf/content-faq acf/faq                   # rename a block (after its directory and block.json)
php gaffer migrate:remove-field acf/content-faq vragen.bron        # delete a removed field's values
php gaffer migrate:remove-block acf/content-team                   # delete a removed block from all content
php gaffer migrate:fields [acf/content-faq]                        # after moving a block's fields from the ACF UI/JSON to fields.php
php gaffer migrate:rollback [backup]                               # undo (no argument: list backups)
```

- Moving fields out of the ACF UI/JSON: `migrate:fields` first. Renames and
  removals refuse while a block still stores data under its old field keys.
- Dry run by default; `--run` writes. It refuses (and writes nothing) when
  anything looks off: the code isn't changed yet, content WordPress doesn't
  reproduce exactly, data in an unexpected shape, a name that already exists,
  a page open in the editor, a block to remove that contains other blocks.
- `migrate:remove-block` reports pages and widgets left empty; deleting those
  is a person's decision.
- `--run` saves the old content to `storage/backups/migrate/` (never
  committed), writes everything in one transaction and reads it back.
  Rollback only restores content nobody has edited since.
- Every `--run` is logged in `storage/logs/migrate.log` (one JSON line:
  outcome `written`/`aborted`/`verify_failed`, reason, backup, the backup it
  `undoes`, user, theme commit, locations with checksums). Check it before
  assuming what state the content is in; `migrate:rollback` without an
  argument lists the backups with their outcome.
- Make a database backup first on a live site, run it right after deploying
  the code (until then a renamed field shows empty), and clear page caches.
- Covers posts of every type and status and block widgets, not revisions.
