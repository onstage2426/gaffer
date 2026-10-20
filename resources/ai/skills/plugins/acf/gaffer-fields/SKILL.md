---
name: gaffer-fields
description: Add or change ACF fields of an options page, a post type or a taxonomy in a Gaffer theme (fields/{options_page|post_type|taxonomy}/{name}.php), or move a field group made in the ACF UI to code. Not for block fields (gaffer-block).
---

# Gaffer field group

1. The file is the location: `fields/options_page/{slug}.php`,
   `fields/post_type/{type}.php` or `fields/taxonomy/{taxonomy}.php`. One file
   per target: sections are tabs (`['label' => 'Footer', 'type' => 'tab']`),
   not separate groups. The post type, taxonomy or options page itself is
   registered in the theme's code (`doctor --wp` checks it exists).
2. Return a list of ACF field arrays, like a block's `fields.php`: no `key`,
   only non-default settings, always `return_format`; shared fields via
   `...Fields::name()`. No flexible content.
3. Options pages share one stored namespace (`options_{name}`): a name is used
   once across all of them.
4. Read the values where they're shown, in PHP: `get_field('x', 'option')`,
   `Acf::option_array()`, `Acf::term_array()`, `$post->meta()` or a type method;
   pass them to the template. `doctor` warns about fields nothing mentions.
5. **Renaming or removing a field** leaves its stored values under the old
   name and key (`doctor --wp` warns about values pointing to a key the file
   no longer has). Ask before renaming fields that already have content.
6. **Moving a group made in the ACF UI to code:** write the file with the same
   field names and types (`gaffer-reference` lists the UI group's fields),
   then `php gaffer migrate:fields {param}/{value}` (dry run, then `--run`
   once the user agrees; it rewrites the stored field references, with a
   backup, and checks ACF reads the same values afterwards). Read the dry
   run's notes: references it leaves alone (other post types, old names,
   broken keys) are yours to judge. Then delete the old group in ACF (that
   removes its JSON too). Until then `doctor` reports the overlap and the
   edit screen shows both.
7. Run `php gaffer doctor --wp`.
