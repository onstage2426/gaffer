// Gaffer's search JavaScript against gaffer-search's recorded endpoint answers
// (tests/fixtures/search-contract/, the plugin's tests/contract/): every answer the endpoint
// gave to a request like the browser's is read the way the contract says. The plugin's CI holds
// the endpoint to these answers; this holds the browser's half to them.
import { describe, expect, test } from "bun:test";
import { readdirSync, readFileSync } from "node:fs";
import { mockFetch } from "./fetch.js";

const F = window.GafferSearch;
const DIR = new URL("../fixtures/search-contract/", import.meta.url);

const cases = readdirSync(DIR)
    .filter((file) => file.endsWith(".json"))
    .map((file) => [file, JSON.parse(readFileSync(new URL(file, DIR), "utf8"))])
    .filter(([, c]) => c.call === "endpoint" && c.expect?.body);

// The case's query as form fields, named the way Gaffer's templates name them: sort[],
// filter[x][] for a list, filter[x][min], filter[any][0][x] for a group's entries.
function fields(query, prefix = "") {
    const out = [];
    for (const [key, value] of Object.entries(query ?? {})) {
        const name = prefix ? `${prefix}[${key}]` : key;
        if (Array.isArray(value) && value.every((v) => typeof v !== "object")) {
            for (const v of value) out.push([`${name}[]`, String(v)]);
        } else if (value !== null && typeof value === "object") {
            out.push(...fields(value, name));
        } else {
            out.push([name, String(value)]);
        }
    }
    return out;
}

// The fixtures are recorded from PHP arrays: an empty map is stored as [], which the endpoint
// sends as {} (SearchRunner::forJson()).
const map = (value) => (Array.isArray(value) && value.length === 0 ? {} : value);
const hits = (results) => results.map((hit) => ("labels" in hit ? { ...hit, labels: map(hit.labels) } : hit));
const wire = (body) => ("results" in body
    ? { ...body, results: hits(body.results), facets: map(body.facets), facetStats: map(body.facetStats), labels: map(body.labels) }
    : body);

function component(query) {
    const inputs = fields(query).map(([name, value]) => {
        const input = document.createElement("input");
        input.type = "hidden";
        input.name = name;
        input.value = value;
        return input;
    });
    document.body.innerHTML = `<form method="get" action="/winkel/" data-gaffer-search-endpoint="https://shop.test/search.php" data-gaffer-search-token="T"></form>`;
    const form = document.querySelector("form");
    form.append(...inputs);
    const c = { ...F.createAlpineSearch(), $root: form, $nextTick: async () => {} };
    c.init();
    c.query = String(query?.q ?? "");
    return c;
}

describe("the browser side of gaffer-search's contract", () => {
    test("there are recorded endpoint answers to read", () => {
        expect(cases.length).toBeGreaterThan(10);
    });

    for (const [file, c] of cases) {
        test(file, async () => {
            const status = c.expect.status;
            const body = wire(c.expect.body);
            const calls = mockFetch(() => new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } }));
            const s = component(c.query);

            await s.run();

            expect(calls.length).toBe(1);
            const sent = new URL(calls[0]).searchParams;
            expect(sent.get("token")).toBe("T");
            for (const name of sent.keys()) {
                // Only parameters the endpoint reads (docs/contract.md, "The browser side").
                expect(["token", "q", "offset", "limit", "sort[]"].includes(name) || name.startsWith("filter[")).toBe(true);
            }

            if (status === 200) {
                expect(s.error).toBeNull();
                expect(s.results).toEqual(body.results);
                expect([s.total, s.offset, s.limit, s.searchedQuery]).toEqual([body.total, body.offset, body.limit, body.query]);
                expect(s.facets).toEqual(body.facets);
                expect(s.facetStats).toEqual(body.facetStats);
                expect(s.labels).toEqual(body.labels);
            } else {
                // 4xx: the page is stale (its token no longer works); 5xx and a request without a
                // token: try again later.
                expect(s.error).toEqual({ message: body.error, code: body.code, status, stale: status < 500 && body.code !== "token_required" });
                expect(s.results).toEqual([]);
            }
        });
    }
});
