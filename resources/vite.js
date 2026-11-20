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
 * - the dev server's address in public/.vite/hotfile while `vite serve` runs (Gaffer\Vite
 *   then serves the dev server's assets to administrators);
 * - public/.vite/gaffer.json: a hash of the Gaffer JavaScript this build bundled, so `doctor`
 *   tells when a composer update brought JavaScript the build doesn't have yet.
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

/** @returns {import("vite").Plugin} */
export default function gaffer() {
    let root = process.cwd();
    let outDir = "public";

    return {
        name: "gaffer",
        config() {
            return {
                base: "./",
                publicDir: false,
                resolve: { alias: { "@gaffer": JS } },
                build: { outDir: "public", emptyOutDir: true, manifest: true },
            };
        },
        configResolved(config) {
            root = config.root;
            outDir = path.resolve(config.root, config.build.outDir);
        },
        writeBundle() {
            fs.mkdirSync(path.join(outDir, ".vite"), { recursive: true });
            fs.writeFileSync(path.join(outDir, ".vite", "gaffer.json"), JSON.stringify({ js: gafferJsHash() }) + "\n");
        },
        configureServer(server) {
            const hotfile = path.resolve(root, "public/.vite/hotfile");
            server.httpServer?.on("listening", () => {
                const address = server.httpServer?.address();
                if (address && typeof address === "object") {
                    fs.mkdirSync(path.dirname(hotfile), { recursive: true });
                    fs.writeFileSync(hotfile, `http://${server.config.server.host ?? "localhost"}:${address.port}`);
                }
            });
            const clean = () => fs.existsSync(hotfile) && fs.rmSync(hotfile);
            process.on("exit", clean);
            for (const [signal, code] of [["SIGHUP", 129], ["SIGINT", 130], ["SIGTERM", 143]]) {
                process.on(signal, () => process.exit(code));
            }
        },
    };
}
