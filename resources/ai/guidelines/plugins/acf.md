## ACF

- `get_field()` returns strings for numbers and `null`/`false` when empty:
  cast IDs (`(int) get_field('image')`) for the type factories.
- Repeaters, galleries, relationships: `Acf::block_array('name')` (in a
  block's `functions.php` only), `Acf::post_array('name', $post)`,
  `Acf::option_array('name')`: always an array. A term's:
  `get_field('name', $term->wp) ?: []`.
- ACF values come back with a plain `&` (Gaffer undoes kses's `&amp;` once;
  wysiwyg keeps its HTML): print them escaped, never `html_entity_decode()`
  them.
- **Field groups are code, never made in the ACF UI:** block fields in
  `blocks/{name}/fields.php`, others one file per target,
  `fields/options_page/{slug}.php`, `fields/post_type/{type}.php`,
  `fields/taxonomy/{taxonomy}.php` (the `gaffer-fields` skill). Options pages
  share one namespace: a field name once across them.
- Registering post types, taxonomies and options pages is theme code (or ACF
  UI JSON in `storage/acf-json/`, committed).
