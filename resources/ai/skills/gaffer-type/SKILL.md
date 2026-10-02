---
name: gaffer-type
description: Add or change a theme type in a Gaffer theme (a subclass of Gaffer\Types\Post or Term in app/Types/, mapped in config/theme.php). Use when a post type or taxonomy needs its own methods.
---

# Gaffer theme type

1. Create `app/Types/{Name}.php`, namespace `Theme\Types`, extending
   `Gaffer\Types\Post` (post types) or `Gaffer\Types\Term` (taxonomies).
2. Map it in `config/theme.php`: `'types' => ['{post_type}' => Theme\Types\{Name}::class]`
   (or `'terms'` for taxonomies). From then on every factory returns it.
3. No constructor or `build()`: read WordPress or plugin data lazily in
   methods and cache it in a private property. Extra context gets an explicit
   method (`select_variation(array $attributes)`), never a factory argument.
4. Methods only where they add something; raw fields stay on `$this->wp`.
   snake_case names, explicit return types, `null` when data is missing (no
   fallbacks, unless the theme explicitly wants one, like a placeholder image).
5. Use it with `{Name}::from($id)` (returns `null` for posts of another type).
6. Run `php gaffer doctor --wp`.
