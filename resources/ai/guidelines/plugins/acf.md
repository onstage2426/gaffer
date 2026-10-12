## ACF

- `get_field()` returns strings for numeric values and `null`/`false` when
  empty: cast IDs (`(int) get_field('image')`) and pass them to the type
  factories, which return `null` for `0`.
- Repeaters, galleries and relationships: `Gaffer\Acf::field_array('name')`,
  `Acf::field_array('name', $post_id)`, `Acf::term_array('name', $term)` (a
  `Term`) and `Acf::option_array('name')` (options pages) always return an
  array (`[]` when empty).
- ACF values come back with a plain `&`, also when WordPress's kses stored
  it as `&amp;` (saved by users without `unfiltered_html`; on a multisite
  everyone but super admins): Gaffer undoes that one entity when ACF loads a
  value, in blocks, on posts, terms and options pages. Wysiwyg fields are HTML
  and keep theirs. Print text escaped (`{{ title }}`, `href="{{ link.url }}"`);
  never `html_entity_decode()` ACF values yourself. Other entities (`&lt;`)
  stay as stored. WordPress's own values are another matter: titles and term
  names are stored escaped, so read them through the types (`title()`).
- Block fields are code (`blocks/{name}/fields.php`, see ACF blocks). Other
  field groups (options pages, post types, taxonomies) are edited in the ACF
  UI and saved as local JSON in `storage/acf-json/`; commit that JSON.
