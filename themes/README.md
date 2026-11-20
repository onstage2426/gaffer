# themes/

Copies of real themes built with Gaffer, for improving Gaffer from actual work. Everything here
except this file is gitignored: themes are other people's code and never get committed.

A feedback round (see `AGENTS.md`, "Site feedback rounds"):

1. At the end of a session in a theme, give its agent `prompts/feedback.md`. It writes
   `gaffer-feedback.md` in the theme's root and prints the transcript's path.
2. Put the theme here as `themes/<site>/`, with that report and the transcript (`.jsonl`).
3. In a session in this repo, give `prompts/process.md` with `<site>` filled in.

`bin/dev/usage themes/<site>` lists the public members a theme uses, and any internal or missing
Gaffer code it relies on.
