/**
 * Gaffer's Vite plugin: the build settings Gaffer's PHP reads (Gaffer\Vite) and what doctor
 * checks, owned by Gaffer so both ends change together. In the theme's vite.config.js:
 *
 *   import gaffer from "./vendor/onstage2426/gaffer/resources/vite.js";
 *   export default defineConfig({ plugins: [gaffer(), tailwindcss()], build: { rollupOptions: { input: entryFiles } } });
 *
 * It sets:
 * - the build Gaffer\Vite reads: public/ with .vite/manifest.json, relative URLs (base "./"),
 *   no publicDir copy;
 * - the alias "@gaffer" for Gaffer's JavaScript (resources/js): import "@gaffer/forms.js";
 * - public/.vite/gaffer.json: a hash of the Gaffer JavaScript this build bundled, so `doctor`
 *   tells when a composer update brought JavaScript the build doesn't have yet, and the files
 *   this build wrote;
 * - a build that keeps the previous build's files: public/ isn't emptied first, and afterwards
 *   only what neither this build nor the previous one wrote is deleted. A page a cache (WP
 *   Rocket, Cloudflare) or an open tab still has from before the build keeps its CSS and
 *   JavaScript, and while the build runs, the old manifest and its files stay in place.
 */
import { createHash } from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const JS = path.join(path.dirname(fileURLToPath(import.meta.url)), "js");

/**
 * sha1 over every file in resources/js (relative path, NUL, content, NUL; sorted by path),
 * the same as Gaffer\Console\Checks\AssetsCheck::gaffer_js_hash().
 *
 * @param {string} dir
 * @returns {string}
 */
export function gafferJsHash(dir = JS) {
    const files = [];
    const walk = (sub) => {
        for (const entry of fs.readdirSync(path.join(dir, sub), { withFileTypes: true })) {
            const rel = sub === "" ? entry.name : `${sub}/${entry.name}`;
            entry.isDirectory() ? walk(rel) : entry.isFile() && files.push(rel);
        }
    };
    walk("");
    files.sort((a, b) => (Buffer.from(a) < Buffer.from(b) ? -1 : Buffer.from(a) > Buffer.from(b) ? 1 : 0));
    const hash = createHash("sha1");
    for (const rel of files) {
        hash.update(rel + "\0");
        hash.update(fs.readFileSync(path.join(dir, rel)));
        hash.update("\0");
    }

    return hash.digest("hex");
}

/**
 * Every file under dir (relative paths), without .vite/.
 *
 * @param {string} dir
 * @returns {string[]}
 */
function filesIn(dir) {
    const files = [];
    const walk = (sub) => {
        for (const entry of fs.readdirSync(path.join(dir, sub), { withFileTypes: true })) {
            const rel = sub === "" ? entry.name : `${sub}/${entry.name}`;
            if (rel === ".vite") continue;
            entry.isDirectory() ? walk(rel) : files.push(rel);
        }
    };
    if (fs.existsSync(dir)) walk("");

    return files;
}

/**
 * The files the previous build wrote (gaffer.json's "files"); before there was such a list,
 * everything in outDir, so the first build deletes nothing.
 *
 * @param {string} outDir
 * @returns {string[]}
 */
function previousFiles(outDir) {
    try {
        const stamp = JSON.parse(fs.readFileSync(path.join(outDir, ".vite", "gaffer.json"), "utf8"));
        if (Array.isArray(stamp.files)) return stamp.files;
    } catch {}

    return filesIn(outDir);
}

/**
 * Deletes the files in outDir that neither build wrote, then the directories left empty.
 *
 * @param {string} outDir
 * @param {Set<string>} keep
 */
export function prune(outDir, keep) {
    for (const rel of filesIn(outDir)) {
        if (!keep.has(rel)) fs.rmSync(path.join(outDir, rel), { force: true });
    }
    const removeEmpty = (dir) => {
        for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
            if (!entry.isDirectory() || entry.name === ".vite") continue;
            const sub = path.join(dir, entry.name);
            removeEmpty(sub);
            if (fs.readdirSync(sub).length === 0) fs.rmdirSync(sub);
        }
    };
    if (fs.existsSync(outDir)) removeEmpty(outDir);
}

/** @returns {import("vite").Plugin} */
export default function gaffer() {
    let outDir = "public";
    /** @type {string[]} */
    let previous = [];

    return {
        name: "gaffer",
        config() {
            return {
                base: "./",
                publicDir: false,
                resolve: { alias: { "@gaffer": JS } },
                build: { outDir: "public", emptyOutDir: false, manifest: true },
            };
        },
        configResolved(config) {
            outDir = path.resolve(config.root, config.build.outDir);
        },
        buildStart() {
            previous = previousFiles(outDir);
        },
        writeBundle(options, bundle) {
            const files = Object.keys(bundle).filter((file) => !file.startsWith(".vite/")).sort();
            prune(outDir, new Set([...files, ...previous]));
            fs.mkdirSync(path.join(outDir, ".vite"), { recursive: true });
            fs.writeFileSync(path.join(outDir, ".vite", "gaffer.json"), JSON.stringify({ js: gafferJsHash(), files }) + "\n");
            previous = files;
        },
    };
}
