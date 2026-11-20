// shrinkUploads(): shrinks opted-in file inputs on change and holds a submit until that's done.
import { describe, expect, test } from "bun:test";
import { shrinkInput, shrinkUploads } from "../../resources/js/forms.js";

function form(html) {
    const root = document.createElement("div");
    root.innerHTML = `<form hx-post="/ajax">${html}<button>Send</button></form>`;
    document.body.append(root);
    return root.querySelector("form");
}

function choose(input, files) {
    Object.defineProperty(input, "files", { value: files, writable: true, configurable: true });
    input.dispatchEvent(new Event("change", { bubbles: true }));
}

/** Counts the submits that reach the form's own listener (where htmx listens). */
function htmxSees(f) {
    let seen = 0;
    f.addEventListener("submit", (event) => {
        event.preventDefault();
        seen++;
    });
    return () => seen;
}

function submit(f) {
    const event = new Event("submit", { bubbles: true, cancelable: true });
    f.dispatchEvent(event);
    return event;
}

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));
let release;
const gate = () => new Promise((resolve) => (release = resolve));
const shrinkAll = async (file) => {
    await gate();
    return new File(["x"], file.name.replace(/\.[^.]*$/, "") + ".jpg", { type: "image/jpeg" });
};

shrinkUploads(document, shrinkAll);

describe("shrinkUploads", () => {
    test("a form without opted-in files is left alone", () => {
        const f = form(`<input type="file" name="files[a]">`);
        const seen = htmxSees(f);
        choose(f.querySelector("input"), [new File(["abc"], "a.jpg", { type: "image/jpeg" })]);
        expect(f.hasAttribute("aria-busy")).toBe(false);
        expect(submit(f).defaultPrevented).toBe(true); // by htmxSees
        expect(seen()).toBe(1);
    });

    test("shrinks on change; a submit meanwhile waits and is sent once", async () => {
        const f = form(`<input type="file" name="files[a][]" multiple data-gaffer-resize="2000">`);
        const seen = htmxSees(f);
        let resubmits = 0;
        f.requestSubmit = () => (resubmits++, submit(f));
        const input = f.querySelector("input");
        choose(input, [new File(["a".repeat(100)], "IMG_1.HEIC", { type: "image/heic" })]);
        expect(f.getAttribute("aria-busy")).toBe("true");

        submit(f);
        submit(f); // a second click while shrinking
        expect(seen()).toBe(0);

        release();
        await tick();
        await tick();
        expect(f.hasAttribute("aria-busy")).toBe(false);
        expect([...input.files].map((file) => file.name)).toEqual(["IMG_1.jpg"]);
        expect(resubmits).toBe(1);
        expect(seen()).toBe(1);

        submit(f); // nothing pending: straight through
        expect(seen()).toBe(2);
    });
});

describe("shrinkInput", () => {
    test("keeps the files when nothing changed or shrinking failed", async () => {
        const input = document.createElement("input");
        input.type = "file";
        input.dataset.gafferResize = "1000";
        const pdf = new File(["%PDF"], "form.pdf", { type: "application/pdf" });
        Object.defineProperty(input, "files", { value: [pdf], writable: true, configurable: true });
        await shrinkInput(input, async (file) => {
            throw new Error("can't decode " + file.name);
        });
        expect([...input.files]).toEqual([pdf]);
    });
});
