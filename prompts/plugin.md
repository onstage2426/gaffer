Add a plugin to Gaffer's catalog for this need: `<need>` (where it comes from: a site report, an Open item, or my request).

1. **Read first:** `AGENTS.md` ("Plugins", Design principles, Settled decisions, Open), the catalog (`plugins/catalog.json`, `bin/dev/plugins`), and gaffer-search as the model: `plugins/gaffer-search/AGENTS.md`, its `docs/contract.md` and the layout of its repo (not its code). Then your memory, if you have one, for earlier decisions about this need.

2. **Decide whether it's a plugin.** Only when it must be one (AGENTS.md: its data outlives the theme, one copy serves a network, or it needs a fixed path). Otherwise propose the right place instead: Gaffer itself, the theme, or a feature of a plugin in the catalog. Settled and declined items stay declined. The catalog stays at about ten plugins: if it's full, say which one this would replace.

   Then which kind: does Gaffer call it (an **engine**: a contract, the catalog), does it only listen to Gaffer's events and WordPress's hooks (a **listener**: a standalone plugin Gaffer doesn't know), or does it only read a site's report (a **reader**: nothing on the site but Gaffer)? Only an engine joins the catalog. (Proposed in `local/plan-1.0.md`, Phase 12: confirm the split with me until it's decided.)

3. **Propose it to me and wait for my go:** its name (`gaffer-<what>`); what it does in one line; what it stores and where (and what `uninstall.php` removes); its contract (the filter `gaffer_<what>_engine`, contract 1, its methods and their answers, which are plain data); what the theme writes (a config file? which `Gaffer\…` API in templates?); its endpoints, if any; what `doctor` checks; which site tests it first. Keep it small: the first contract only has what the first site needs.

4. **After my go, the plugin** in `plugins/<name>/` (`git init -b 0.x`), laid out like gaffer-search:
   - the plugin file with its header, `composer.json` (MIT, the PHP version Gaffer requires), `src/Engine.php` answering the filter, `uninstall.php`, `LICENSE`, a short `README.md`;
   - `docs/contract.md`, `tests/contract/*.json` + `MANIFEST` + `ContractTest` (recorded cases are never edited), `API.md` + `api.txt` + `ApiTest`, `docs/migration.md` (empty "Unreleased");
   - CI and release workflows, phpstan/phpcs, `.deployignore`;
   - `AGENTS.md` with only what's particular to it (what it is and why it's a plugin, where things are, checks/deploys/releases, key design, gotchas), pointing to Gaffer's `AGENTS.md` for the shared workflow.

   No site names, hostnames or host paths in its files: host details go in Gaffer's `local/AGENTS_LOCAL.md`. Ask me for the GitHub repository (I create it); then add it to `plugins/catalog.json`.

5. **Gaffer's side:**
   - the row in AGENTS.md's catalog table;
   - an entry in `Ai\Plugins` with its guideline (`resources/ai/guidelines/plugins/<name>.md`: a few lines of rules) and skill (`resources/ai/skills/plugins/<name>/`: the procedure and details), per AGENTS.md's "Guidelines and skills for themes";
   - the theme-facing API (public, experimental until the plugin's 1.0, in `API.md`), a config stub if the theme configures it;
   - the contract's fixtures copied to `tests/fixtures/<name>-contract/` with a test that reads every recorded answer, and one that compares the copy with the plugin clone (like `SearchContractTest`; skipped without a clone);
   - `doctor` checks (warnings), a `docs/architecture.md` row, a CHANGELOG entry (new, nothing breaks).

6. **Prove it:** `composer check` in the plugin; `composer test`, phpstan and `bun test` in Gaffer; `bin/dev/contract <name>` without mismatches (add the plugin's `filter` and `fixtures` to `plugins/catalog.json`); the plugin deployed on the integration site with `php gaffer doctor --wp` clean and the feature working in a render (and a browser, if it has JavaScript). A deactivated plugin must leave every page rendering.

7. **Don't commit:** tell me what's in both trees so I can check, then commit and push as AGENTS.md says. Save what was decided (AGENTS.md's Open for later steps, a memory note if you have memory).

8. If this prompt missed a step you had to work out, edit it and add a line under "Lessons".

## Lessons
