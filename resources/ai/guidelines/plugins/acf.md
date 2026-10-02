## ACF

- `get_field()` returns strings for numeric values and `null`/`false` when
  empty: cast IDs (`(int) get_field('image')`) and pass them to the type
  factories, which return `null` for `0`.
- Repeaters, galleries and relationships: `Gaffer\Acf::field_array('name')`,
  `Acf::field_array('name', $post_id)` and `Acf::option_array('name')` (options
  pages) always return an array (`[]` when empty).
- Field groups are edited in the ACF UI and saved as local JSON in
  `storage/acf-json/`; commit that JSON.
