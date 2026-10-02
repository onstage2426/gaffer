---
name: gaffer-block
description: Create or change an ACF block in a Gaffer theme (blocks/{name}/ with block.json, functions.php and a Twig template). Use when adding a block, changing a block's fields or markup, or renaming a block.
---

# Gaffer block

1. Pick the closest existing block in `blocks/` and copy its three files to
   `blocks/{camelName}/`. Rename the template to `{camelName}.twig`.
2. `block.json`: `"name": "acf/{kebab-name}"` (the directory in kebab-case),
   a real one-line `description`, the same `category` as the other blocks.
3. `functions.php`: keep the `if (is_admin()) { return; }` guard first. Gather
   the data (`get_field()`, `(int)` casts, `Image::from()`, `Post::from()`,
   `Acf::field_array()`) and call
   `View::render('@block/{camelName}/{camelName}.twig', [...])`.
4. Template: markup only, from the variables you passed. Images:
   `<img class="..." {{ image.attrs('large') }} loading="lazy">` inside
   `{% if image %}`.
5. Fields: tell the user which fields to create in the ACF UI (field group
   location: Block is `acf/{kebab-name}`). The JSON lands in
   `storage/acf-json/`. Don't hand-write ACF JSON.
6. **Renaming an existing block** changes its name in post content and in
   field group location rules: both must be migrated, or pages lose the block.
   Ask before renaming.
7. Run `php gaffer twig:lint` and `php gaffer doctor --wp`.
