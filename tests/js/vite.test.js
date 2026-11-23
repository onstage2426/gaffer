import { afterEach, expect, test } from "bun:test";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { KEEP_MS, prune } from "../../resources/vite.js";

let dir;
afterEach(() => fs.rmSync(dir, { recursive: true, force: true }));

test("prune keeps both builds' files and anything younger than a week", () => {
    dir = fs.mkdtempSync(path.join(os.tmpdir(), "gaffer-prune-"));
    fs.mkdirSync(path.join(dir, "assets/old"), { recursive: true });
    const write = (rel, ageMs) => {
        fs.writeFileSync(path.join(dir, rel), "x");
        const t = new Date(Date.now() - ageMs);
        fs.utimesSync(path.join(dir, rel), t, t);
    };
    write("assets/main-new.js", 0);
    write("assets/main-previous.js", 2 * KEEP_MS);
    write("assets/main-yesterday.js", 24 * 60 * 60 * 1000); // two builds ago, a cached page may still load it
    write("assets/old/main-month.js", 30 * 24 * 60 * 60 * 1000);

    prune(dir, new Set(["assets/main-new.js", "assets/main-previous.js"]));

    expect(fs.existsSync(path.join(dir, "assets/main-new.js"))).toBe(true);
    expect(fs.existsSync(path.join(dir, "assets/main-previous.js"))).toBe(true);
    expect(fs.existsSync(path.join(dir, "assets/main-yesterday.js"))).toBe(true);
    expect(fs.existsSync(path.join(dir, "assets/old"))).toBe(false); // emptied, then removed
});
