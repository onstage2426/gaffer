## ACF

- `get_field()` returns strings for numeric values and `null`/`false` when
  empty: cast IDs (`(int) get_field('image')`) and pass them to the type
  factories, which return `null` for `0`.
- Repeaters, galleries and relationships: `Gaffer\Acf::field_array('name')`,
  `Acf::field_array('name', $post_id)` and `Acf::option_array('name')` (options
  pages) always return an array (`[]` when empty).
- Block field values come back with a plain `&`, also when WordPress's kses
  stored it as `&amp;` (posts saved by users without `unfiltered_html`;
  on a multisite everyone but super admins): Gaffer undoes that one entity
  when ACF loads block data. Print text escaped (`{{ title }}`,
  `href="{{ link.url }}"`); never `html_entity_decode()` block values
  yourself. Other entities (`&lt;`) stay as stored.
- Block fields are code (`blocks/{name}/fields.php`, see ACF blocks). Other
  field groups (options pages, post types, taxonomies) are edited in the ACF
  UI and saved as local JSON in `storage/acf-json/`; commit that JSON.
