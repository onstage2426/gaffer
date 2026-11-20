# plugins/

Gaffer's plugins, each its own repository, cloned here (gitignored except this file and
`catalog.json`). `AGENTS.md` ("Plugins") has the catalog and the rules every plugin follows;
each plugin's own `AGENTS.md` has the rest.

```sh
bin/dev/plugins clone   # clone the plugins in catalog.json that are missing
bin/dev/plugins         # each plugin: branch, uncommitted files, commits not pushed, last tag
bin/dev/contract        # the boundary between Gaffer and each plugin, both ways, and mismatches
```

A new plugin: `prompts/plugin.md`.
