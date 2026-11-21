---
name: ship-change
description: Ship a Gaffer change end to end - implement, test, migration book entry, commit, then (after the user pushes) update and verify the local sites (bin/dev/sites, local/sites.json). Use for any change to Gaffer's src/ or config stubs.
---

# Ship a Gaffer change

1. **Plan against real usage.** Grep the integration site's theme
   (`local/AGENTS_LOCAL.md`; the starter has less) for
   every call site the change affects before designing it. If it touches many
   templates, show the user one converted example first.

2. **Implement in this repo** (branch `0.x`). Follow the design principles in
   `AGENTS.md`. New config keys go into the stubs in `config/`.

3. **Test.**
   - `composer test`, adding tests for anything that doesn't need WordPress
     (stub the few WP functions you need in `tests/stubs/wordpress.php`).
   - `vendor/bin/phpstan analyse --memory-limit=1G`: must be clean, no baseline.
   - Public API changed on purpose: `UPDATE_API=1 composer test` updates
     `tests/api.txt`; read its diff.
   - WordPress-dependent code: try it against the integration site with this clone's
     autoloader loaded first (snippet in `AGENTS.md`).

4. **Migration book.** If a site has to change anything (renamed/removed
   API, new required config, behavior change), append an entry to
   `CHANGELOG.md` (newest first, under today's date): what changed, a `grep` to
   find affected code, before/after, what to check. It's committed and ships
   with the package: sites read it after updating.

5. **Commit** (one change, a short imperative subject, see "Commits" in
   `AGENTS.md`; no attribution lines). Tell the
   user what's unpushed and what the integration site and the starter will need.
   If the change breaks them, say that they're broken between their `composer update`
   and your follow-up.

6. **After the user pushes:** `bin/dev/sites update` (every local site in `local/sites.json`:
   `composer update` until the lock has the pushed commit, build, `ai:update`, `twig:lint`,
   commit `composer.lock` where allowed), apply the migration entry where it asks for a change,
   then:
   - `bin/dev/sites run --summary -- doctor --wp`
   - `bin/dev/sites check` when the change touches what a browser does (forms, ajax, JS)
   - anything specific to the change through Apache (ajax, pages, admin bar)
   - update the theme's `AGENTS_DEV.md` where the change is documented
   - commit what the migration changed (the integration site on its own branch), leaving its
     local `config/theme.php` debug setting uncommitted.

   Before pushing, `bin/dev/gaffer-test <site> doctor --wp` runs the unpushed code on a site.
