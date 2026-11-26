Write a feedback report for the maintainers of Gaffer and its plugins about this session (what you built, sliced or restructured). Leave out site-specific design work.

Start with the versions: Gaffer's reference in composer.lock, the version of each Gaffer plugin the site runs (`wp plugin get <plugin> --field=version`, e.g. gaffer-search), PHP's version, and whether you used the MCP tools or the CLI.

Then, per item: the concrete command, file, class or skill; what happened (the exact error or output, shortened); what you expected; and how you got around it. Group them:

1. Broken or wrong: commands, MCP tools, doctor checks (false errors, missed problems), classes, the JS, and guidelines or skills that said something untrue or out of date.
2. Restructuring: what made moving or renaming code, templates, blocks, fields or content hard. Which migrate/render commands helped, which were missing or refused, and what you checked by hand to be sure nothing changed.
3. Workarounds in theme code that a framework feature should cover. Name the file and say whether another site would need the same thing. Also code the theme copied from the starter (cart actions, notices, forms) that you had to fix or change: the file and what.
4. Done by hand more than once (how often, and roughly how long it took).
5. Slow or flaky (with timings).
6. What worked well and should stay as it is (short).

Be honest about your own mistakes: when a problem was yours and not the tools', say so, and say what in the docs would have prevented it. Don't propose big new features. For each item, say what the smallest change in Gaffer or gaffer-search would be.

Also point out where Gaffer overstepped or forced you into a bad design.

Don't repeat an item an earlier report already raised and the maintainers answered (declined or deferred), unless it cost you time again: then say so in one line.

Save the report as `gaffer-feedback.md` in the theme's root (overwrite an older one) and also print it. At the end, give the full path of this session's transcript (.jsonl).
