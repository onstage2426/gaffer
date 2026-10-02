---
name: ship-change
description: Ship a Gaffer change end to end - implement, test, migration book entry, commit, then (after the user pushes) update and verify the blueprint theme. Use for any change to Gaffer's src/ or config stubs.
---

# Ship a Gaffer change

1. **Plan against real usage.** Grep the blueprint theme
   (`~/docker/appdata/websites/blueprint/wp-content/themes/blueprint`) for
   every call site the change affects before designing it. If it touches many
   templates, show the user one converted example first.

2. **Implement in this repo** (branch `0.x`). Follow the design principles in
   `AGENTS_DEV.md`. New config keys go into the stubs in `config/`.

3. **Test.**
   - `composer test`, adding tests for anything that doesn't need WordPress
     (stub the few WP functions you need in `tests/stubs/wordpress.php`).
   - `vendor/bin/phpstan analyse --memory-limit=1G`: must be clean, no baseline.
   - WordPress-dependent code: try it against blueprint with this clone's
     autoloader loaded first (snippet in `AGENTS_DEV.md`).

4. **Migration book.** If a site has to change anything (renamed/removed
   API, new required config, behavior change), append an entry to
   `MIGRATION.md`: what changed, a `grep` to find affected code, before/after,
   what to check. `MIGRATION.md` is never committed.

5. **Commit** with the message `general` (no attribution lines). Tell the
   user what's unpushed and what blueprint will need. If the change breaks
   blueprint, say that blueprint is broken between their `composer update`
   and your follow-up.

6. **After the user pushes:** in blueprint, `composer update onstage2426/gaffer`,
   apply the migration entry to blueprint, then:
   - `php gaffer twig:lint`
   - `php gaffer doctor --wp`
   - anything specific to the change through Apache (ajax, pages, admin bar)
   - update blueprint's `AGENTS_DEV.md` where the change is documented
   - commit blueprint with `general`, leaving its local `config/theme.php`
     debug setting uncommitted.
