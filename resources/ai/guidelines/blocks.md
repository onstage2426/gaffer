## ACF blocks (`blocks/`)

One camelCase directory per block (the `gaffer-block` skill):
`blocks/heroContent/` with `block.json` (name `acf/` + the directory in
kebab-case, a real one-line description, the theme's category,
`"acf": { "blockVersion": 3, "renderTemplate": "functions.php" }`),
`fields.php` (its ACF fields, if any), `functions.php` and `heroContent.twig`.

```php
// functions.php
if (is_admin()) {
    return; // editor previews are empty on purpose
}

View::render('@block/heroContent/heroContent.twig', [
    'title' => get_field('title'),
    'image' => Image::from((int) get_field('image')),
]);
```

- The `is_admin()` guard first; objects resolved before rendering
  (`Post::from()`, `Image::from()`, `Acf::block_array()`); every field read
  once, straight into the render array, by name in the block's own
  `functions.php` (an `app/` class doing the work gets the values passed).
- `fields.php` returns a list of ACF fields without `key` (Gaffer derives
  them: `field_hero-content__titel`) and without ACF's defaults, except
  `return_format` (always set: the PHP depends on it). Conditional logic by
  field name. Shared fields are static methods on `Theme\Fields`, spread in
  (`...Fields::background()`); they must not need WordPress. Never block
  fields in the ACF UI.
- **No `>` inside an attribute** in a block's markup: block output is post
  content, and wptexturize takes it for the end of the tag. Write `&gt;` or
  move it into CSS or an `Alpine.data()` method. `php gaffer doctor` reports
  it (`twig:lint` doesn't).

### Stored content

**Block names, field names and what a value means are stored in post
content.** Renaming or removing a block or field, or changing a field's type,
means migrating that content with `migrate:*` (dry run, then `--run` once the
user agrees): the `gaffer-migrate` skill. Tab labels aren't stored. A type
changes in place only when the stored value means the same (text → wysiwyg,
select → radio, image → file); otherwise a field with a new name.

- Filling a field in stored blocks: `php gaffer migrate:set acf/name field '<json>'`.
- Content written by code (demo or launch pages) uses
  `Gaffer\Blocks::markup('acf/name', [field => value])`, never hand-written
  block comments; pass `wp_slash($content)` to `wp_insert_post()`/`wp_update_post()`.
  Save a post's content before a script overwrites it (only `migrate:*` keeps
  backups). Never write a converter or a throwaway script that writes content.
