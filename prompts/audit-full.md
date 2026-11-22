Audit the whole stack for stability, from the bottom up: Fuzor, each Gaffer plugin (`plugins/`),
Gaffer, the starter and the themes that run them. The goal: 1.x of Gaffer and of each plugin for
as long as possible, which means two things:
- **Before a freeze:** find every break that is only possible before a major (an API, a contract
  key, a stored format, a convention a theme writes by hand) and decide it now. After 1.0 a change
  there is a deprecation and a 2.x, not a fix.
- **After it:** nothing that runs on a site (PHP a theme calls, Twig, the bundled JavaScript, the
  plugins' runtime, stored data, cached pages) breaks through an update of any layer, and every
  failure is loud.

Tooling (CLI, doctor, MCP, AI files, `bin/dev/`) only counts where it can break that. This is
the widest audit; the three narrower ones are its methods: `prompts/audit.md` (Gaffer: surface
map, churn, platform timelines, self-adapting pairs), `prompts/audit-gaffer-search.md` (the
plugin: the boundary's clocks, the endpoint, tokens, stored data) and
`prompts/audit-break-first.md` (the running system ranked by what breaks first, archives, CLI
only). Use their steps where this one points to them, and their "Lessons".

Don't change code in this session. The output is the triage in step 8; I decide what gets built.
The parts in steps 3–7 are mostly independent: if you can run subagents, give each one part with
the files to read, and check what they bring back against the code yourself.

## 1. Read first

- Gaffer's `AGENTS.md` (all of it: Plugins, Design principles, Settled, Gotchas, Open), `API.md`
  (the promise, "Deprecating", "Tooling"), `tests/api.txt`, `docs/architecture.md`,
  `docs/compat-debt.md`, `CHANGELOG.md`.
- Each plugin's `AGENTS.md`, `API.md` and `api.txt`, `docs/contract.md`, `docs/hosting.md`,
  `docs/migration.md`, `docs/compat-debt.md`, and Gaffer's copy of its fixtures
  (`tests/fixtures/<plugin>-contract/`).
- The libraries a site runs that we own: Fuzor (`vendor/onstage2426/fuzor` in the plugin; its
  `CHANGELOG.md`, `UPGRADE-2.0.md`, `docs/operations.md`), the updater library the plugins bundle.
- `local/AGENTS_LOCAL.md` (its leads too), `local/sites.md`, the latest reports in `feedback/`,
  your memory (the earlier audits, the pre-1.0 audit and its open decisions).
- Working trees: `git status` in Gaffer and each plugin, `bin/dev/plugins`. Uncommitted work isn't
  yours: say what it is and ask.

Don't re-propose what Settled, compat-debt, Open or memory already decided. Platform minimums
follow Enterprise Linux (SQLite 3.46, PHP 8.5): don't propose lowering them.

## 2. Where everything is

The table from `audit-break-first.md` step 2, one row per site (local: `bin/dev/sites status
--online` and `bin/dev/sites run --summary -- doctor --wp`; remote: their reports; copies in
`themes/`: `bin/dev/doctor-copy`), plus per layer: the version a site runs, the last tag, the
branch head, and what a site is behind on (CHANGELOG, each plugin's `migration.md`). Every
`composer.lock` reference and every release zip a site could install must still download.

## 3. The dependency chain, bottom up

For each layer (Fuzor → plugin → Gaffer PHP → Gaffer JavaScript → theme), with the layer below:
- **What it calls:** the exact classes, methods, properties, options, hooks, keys and files.
  Mark each as public and promised, public but not promised (experimental, "free to change"), or
  internal. Calling anything but the first is an item.
- **What the layer below promises:** semver and its scope (API only, or also results, sort order,
  warnings text, file formats), how long a major lives, which files and stored data survive a
  minor. Is what we rely on inside that promise? Passing a library's text or shape straight to a
  site (warnings, error messages, result keys) makes its "free to change" our break.
- **How we pin it:** a tag range, a branch, a commit, a VCS repository entry. A dev pin or a
  fork in a released package is an item.
- **Workarounds:** code that compensates for the layer below (a copied constructor, an extra
  field, one more value requested to detect a cut). Each is either fine for good or a request to
  that layer: say which, and whether the request breaks anything there.

The libraries we don't own (WordPress, WooCommerce, ACF, Gravity Forms, WPML, Twig, htmx,
Alpine, Vite, PHP, SQLite): `audit.md` step 4, with the dates.

## 4. The freeze review

Go through everything a site writes or reads that we'd promise at 1.0, one line each:
- every public member in `tests/api.txt` and each plugin's `api.txt` (`bin/dev/usage`, with
  `--unused`, says who uses it);
- every convention in `API.md`: request keys, template names and variables, derived keys,
  config keys, folder layout, the JavaScript exports and events, `data-*` attributes;
- each plugin's contract: methods, request and answer keys, error codes, features in
  `supports()` (which would fold into the baseline at its 1.0), the browser ↔ endpoint keys,
  tokens;
- stored data: options and their shape, index files and their format, tables, files in
  `GAFFER_STORAGE`, compiled caches, anything a cached page carries.

Per line, one verdict: **keep** (stable as it is), **change before 1.0** (name, shape or
behaviour we'd regret; what breaks and who must adapt), **mark experimental or internal** (not
ready to promise), or **remove** (unused, or a second way to do something). Weigh against the
design principles (one way per thing, Gaffer owns both ends of a pair, null for not found,
factories take an ID). Breaking now is preferred over carrying a messy design: but each break
costs every site a migration, so group them.

## 5. What guards each promise

For every promise in step 4 and every crossing in step 3: which check would fail if it broke,
and when: `tests/api.txt`, the contract fixtures and `bin/dev/contract`, PHPUnit, phpstan,
`doctor` (static and `--wp`), `ContractsCheck`, the plugins' `status` and daily health check,
the admin bar health line, the browser checks (`bin/dev/sites check`). A promise nothing checks
is an item; so is a check that can't run and stays quiet. Run the checks you name; don't assume.

## 6. Updates arriving apart, for real

`audit-break-first.md` step 3 ("Updates arriving apart", "How updates reach a site") across every
layer now: each pair of neighbours, one side a release older. Try them on a local site (plugin
`0.x` with Gaffer's previous push, Gaffer `0.x` with the plugin's last tag, an older theme build
with newer PHP, a cached page's token after an update) and roll each back. Then the jumps a site
actually makes: from the oldest version a site runs (step 2) to the heads.

## 7. Fails silently

`audit-break-first.md` step 3 and `audit-gaffer-search.md` step 6, over the whole stack: an empty
list instead of an error, a stale index, a cached broken page, a job that stopped, a check that
reads "nothing to do" when it couldn't ask. Measure: render with a plugin left out for one
process, fetch over HTTP like a visitor, read the source of every fallback.

## 8. Present the triage, then wait

Three lists, most dangerous first in each:
1. **Before a freeze** (step 4's "change" and "remove", step 3's dependency items): what changes,
   who must adapt (`bin/dev/usage`, the theme copies), the migration, and which freeze it blocks
   (Gaffer 1.0, a plugin's 1.0). Group what should land in one cycle.
2. **Breaks or fails silently now:** per item, what breaks and for whom, how likely and when
   (with the source), how it would be noticed today, the smallest fix that keeps existing sites
   working, where it goes, and whether it needs a contract addition, a stored-format change or a
   major.
3. **Unguarded promises** (step 5) and checks to add.

Then what you'd leave as it is and why, in a few lines. Wait for my answer.

## 9. After my answer

Record what was decided: each repository's `docs/compat-debt.md` (public knowledge only), the
freeze verdicts where the next audit will look (Gaffer's compat-debt, a plugin's compat-debt),
`local/AGENTS_LOCAL.md` for what's about a site, Open in `AGENTS.md` for deferred decisions, and
a memory note if you have memory. Improve this prompt where it missed something, with a line
under "Lessons". Keep reusable scripts in a `bin/` of the repository they test.

## Lessons

The narrower audits' lessons apply here. From building this prompt (2026-11-22):
- **Ask before a dependency freezes, not after:** before Fuzor tagged 2.0 the question was what
  the plugin would still need changed there; the answer came from listing every Fuzor symbol the
  plugin calls and checking each against `@internal` and the changelog. Do the same for every
  layer before its 1.0, ours included.
- **A dev pin outlives its reason:** the plugin required `^2.0@dev` through a VCS repository
  until the tag; after a tag, switch the range and drop fallbacks for values the new release
  guarantees (`totalHits` is never null in 2.0).
