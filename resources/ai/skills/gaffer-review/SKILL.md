---
name: gaffer-review
description: Review changes in a Gaffer theme before calling them done - run the Gaffer checks and walk the convention checklist. Use after any non-trivial change, or when asked to review.
---

# Gaffer review

1. Run `php gaffer twig:lint` and `php gaffer doctor --wp`; fix every error and
   look at every warning.
2. Walk the changed files:
   - PHP only gathers data and calls `View::render()`; templates don't look
     anything up.
   - Objects come from the factories with `int` IDs (`(int)` casts on ACF
     values); `null` is handled where data can be missing; no hidden fallbacks.
   - No hardcoded IDs or `wp-content/uploads/` paths; menus by location; theme
     graphics in `views/components/icons/`.
   - Images use `{{ image.attrs(size) }}` inside a written `<img>` tag; no new
     `|raw` without a reason.
   - Views: no markup in PHP outside the `header.php`/`footer.php` shell, no
     `{% extends %}`; partials via `include(..., with_context = false)`; a
     block template isn't rendered by a page (shared markup is a component).
   - Ajax: typed `run()` parameters (types for IDs, arrays for sets of
     values, never JSON strings), correct `METHOD`, no manual casting; actions
     that change something answer with the changed HTML (no refetch).
   - Blocks: `is_admin()` guard, name matches the directory, fields in
     `fields.php` (a renamed or removed block or field needs a `migrate:*`;
     a changed type only when the stored value means the same), no
     InnerBlocks or flexible content.
   - `inc/`: only hook registrations, each with a `/* Area - what */`
     comment and typed closures; no functions or classes declared there
     (they go in `app/`).
   - Assets: modules import what they use (globals only for HTML attributes,
     with a comment); no hardcoded colours (theme tokens); custom CSS only
     for markup the theme doesn't write or library state classes; built.
   - `app/`: snake_case methods; Twig extensions only shape values (no
     fetching, shortcodes or plugin calls); templates use type methods, no
     `.get_*()` on WordPress/WooCommerce objects.
   - snake_case methods, explicit return types, no alias methods.
3. Report what you checked and anything left over, plainly.
