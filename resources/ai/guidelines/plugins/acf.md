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
- **Field groups are code, never made in the ACF UI.** Blocks:
  `blocks/{name}/fields.php` (see ACF blocks). Everything else: one file per
  target, named after ACF's location param, `fields/options_page/{slug}.php`,
  `fields/post_type/{type}.php`, `fields/taxonomy/{taxonomy}.php`, returning
  the same list of fields as a block's `fields.php` (no keys; sections are
  tabs). Gaffer derives the keys (`field_post_type_review__score`). Follow the
  `gaffer-fields` skill. All options pages share one stored namespace, so a
  field name is used once across them (`doctor` reports it).
- Registering a post type, taxonomy or options page is the theme's code
  (`register_post_type()`, `acf_add_options_page()`); ones made in the ACF UI
  stay in `storage/acf-json/` (commit that JSON).
