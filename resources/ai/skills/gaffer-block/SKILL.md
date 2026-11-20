---
name: gaffer-block
description: Create or change an ACF block in a Gaffer theme (blocks/{name}/ with block.json, functions.php and a Twig template). Use when adding a block, changing a block's fields or markup, or renaming a block.
---

# Gaffer block

1. Pick the closest existing block in `blocks/` and copy its files to
   `blocks/{camelName}/`. Rename the template to `{camelName}.twig`.
2. `block.json`: `"name": "acf/{kebab-name}"` (the directory in kebab-case),
   a real one-line `description`, the same `category` as the other blocks.
3. `functions.php`: keep the `if (is_admin()) { return; }` guard first. Gather
   the data (`get_field()`, `(int)` casts, `Image::from()`, `Post::from()`,
   `Acf::block_array()`) directly in the
   `View::render('@block/{camelName}/{camelName}.twig', [...])` array.
4. Template: markup only, from the variables you passed. Images:
   `<img class="..." {{ image.attrs('large') }} loading="lazy">` inside
   `{% if image %}`.
5. Fields: write `fields.php` (a list of ACF field arrays, no `key`, only
   non-default settings; shared fields via `...Fields::name()`). Don't create
   block fields in the ACF UI or in `storage/acf-json/`.
6. **Renaming or removing an existing block or field** changes what's stored
   in post content: change the code, then `php gaffer migrate:rename-block` /
   `migrate:rename-field` / `migrate:remove-block` / `migrate:remove-field` (the `gaffer-migrate` skill: dry run first, then `--run` once the user
   agrees; it writes site content). Ask first. Tab labels can change freely.
7. **Changing a field's type:** in place only when the stored value means the
   same (text → wysiwyg, select → radio); otherwise a new field name (see the
   guideline). No InnerBlocks, no flexible content.
8. Run `php gaffer twig:lint` and `php gaffer doctor --wp`.

## Content written by code

`Gaffer\Blocks::markup('acf/name', [field => value])` writes a block's comment
with every derived key, and refuses an unknown field or a value the field
can't take: repeater rows are lists of arrays by sub field name, true/false a
bool, images and files their ID, links `['title' => …, 'url' => …, 'target' => …]`.
`wp_insert_post()`/`wp_update_post()` unslash their input: pass
`wp_slash($content)`, or the JSON loses its backslashes. Many writes of one
kind go in one script (`wp eval-file /tmp/pages.php`): WP-CLI boots WordPress
on every call. Before a script overwrites a post's content, save it
(`wp post get <id> --field=post_content > /tmp/<id>.html`).
