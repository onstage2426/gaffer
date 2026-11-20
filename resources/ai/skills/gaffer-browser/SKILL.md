---
name: gaffer-browser
description: Look at pages in a headless browser in a Gaffer theme - screenshots of a page or a state (render:screenshot), and building from a design by measuring it against the page (render:compare, render:styles). Use after a change with a visual effect and when slicing a design.
---

# Gaffer in the browser

## Setup

Playwright in the theme (`npm install --save-dev playwright`, then
`npx playwright install chromium`), or a Playwright server at
`PLAYWRIGHT_WS_ENDPOINT` in `.env` (its exact version, basic auth as
`wss://user:pass@host`). That remote browser can't read this machine's files:
give a design by URL. A design behind a login: its cookie as
`DESIGN_COOKIE=name=value` in `.env`. The browser is Chromium: Safari/iOS
quirks (date inputs, `100vh`) only show on a device, so ask the user to check those.

## Screenshots

`php gaffer render:screenshot <path|design URL|file>` writes PNGs at 390 and
1440 px (`--width=390,1440`) to `storage/screenshots/{slug}/` and prints the
page's console errors and failed requests. Read the PNGs.

- **States:** `--click`, `--hover`, `--fill=selector=value`,
  `--select=selector=value` (an option by value or text) and `--scroll=<px|selector>`
  run in the order given, on the first visible match (`--fill` splits at the
  first `=` outside `[...]` and quotes). `--steps=<json>` is the same as one
  list. `--local-storage key=value` for what a returning visitor has stored;
  `--session=<name>` for an `ajax:call` session's visitor (its cart).
- `--clip=<selector>` shoots one element whole; `--full` the whole page in
  parts of `--slice=1200` px (the window is page-tall then: fixed elements sit
  at the bottom, and one moved off-screen with a transform can show; check
  those without `--full`).
- A run replaces its widths' files, `--full` parts included: read a shot before
  the next run of the same page.
- Check every state a page remembers or toggles (stored, clicked, hovered),
  not only a new visitor's first screen.

## Building from a design

Measure, don't eyeball, and never read the design's CSS files or write your
own Playwright scripts for this:

- `php gaffer render:compare <design> <path> [--within=footer] [--width=390]`
  pairs every text of the design with the same text on the page and lists what
  differs (size, weight, line height, letter spacing, font, colour, the gap
  above it) and what the page lacks. One width per run: run it at the design's
  widths after each round of fixes, until it lists nothing you didn't decide
  to keep.
- `php gaffer render:styles <design|path> <selector>... [--children]` gives
  one element's computed values (and its children's boxes, for alignment).

The MCP tools `gaffer-screenshot`, `gaffer-compare` and `gaffer-styles` do the
same when the MCP server is configured.
