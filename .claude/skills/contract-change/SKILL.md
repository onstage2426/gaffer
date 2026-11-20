---
name: contract-change
description: Change what crosses the boundary between Gaffer and one of its plugins (a contract method, a request or answer key, a feature, an endpoint answer) in both repos, so that each side keeps working with the other's previous release. Use whenever a change needs the plugin and Gaffer to agree.
---

# Change a plugin contract

The contract only grows (AGENTS.md, "Plugins"; the plugin's `docs/contract.md`, "The rules"). A
change that would alter or remove what's there is a new feature instead, or a new contract
number: stop and ask the user.

1. **Map it first.** `bin/dev/contract <plugin>` shows each method with its implementation,
   Gaffer's wrapper and callers, the features and who checks them, the hooks, and the fixtures.
   Read the plugin's `docs/contract.md` section for what you change, not the whole plugin.
   Check which sites would use it: their `config/` and templates in `themes/` and on this host.

2. **The plugin first** (`plugins/<name>/`):
   - write the addition in `docs/contract.md`, under its method or shape and under Features
     (name, what it adds, the plugin version that adds it);
   - implement it, with `supports('<feature>')` true;
   - record cases: `RECORD_CONTRACT=1 composer test` writes only missing ones. Never edit or
     delete a recorded case: one that fails means the change breaks the contract;
   - an entry in `docs/migration.md` ("Unreleased") if a site should do something;
   - `composer check`, `bun run build`.

3. **Gaffer second:**
   - copy the fixtures: `cp plugins/<name>/tests/contract/{*.json,MANIFEST} <fixtures dir>/`
     (the catalog's `fixtures`);
   - use the feature only behind `$engine->supports('<feature>')`. Without it, behave exactly
     as before. Where a theme's config asks for it, `doctor` names the plugin version that
     brings it (a warning, or info);
   - tests against the recorded answers (the contract test reads every case), `composer test`,
     phpstan, `bun test` for JavaScript;
   - the CHANGELOG entry: what a site gets, and which plugin version it needs.

4. **Check the boundary again:** `bin/dev/contract <plugin>` has no mismatches.

5. **Prove both update orders on the integration site:** the new plugin with the current
   Gaffer (the theme's `vendor/`), and the new Gaffer (the entry file in AGENTS.md, "Testing
   against real WordPress") with the plugin's last release. For both: `php gaffer doctor --wp`,
   and `render:snapshot` before / `render:diff` after, with no change a site didn't ask for.

6. **Commit each repo on its own** (AGENTS.md, "Commits"). Push the plugin first: Gaffer's
   copy of the fixtures must never be ahead of what the plugin has pushed. The user tags the
   plugin release.
