import { describe, expect, test } from "bun:test";

const F = window.GafferSearch;

function slider(options = {}) {
    document.body.innerHTML = `<form><input type="number" name="min" min="0" max="100" value="0">
        <input type="number" name="max" min="0" max="100" value="100"></form>`;
    const [min, max] = document.querySelectorAll("input");
    const shown = [];
    const changes = [];
    document.querySelector("form").addEventListener("change", (e) => changes.push(e.target.name));
    const s = F.createDualRangeSlider(min, max, { onDrag: (v) => shown.push(v), ...options });
    const handles = document.querySelectorAll(".gaffer-search-range-thumb");
    return { s, min, max, shown, changes, handles };
}

const key = (el, k) => el.dispatchEvent(new KeyboardEvent("keydown", { key: k, bubbles: true }));

describe("createDualRangeSlider", () => {
    test("renders two accessible handles and hides the real inputs visually", () => {
        const { min, handles } = slider({ ariaLabels: ["van", "tot"], formatValue: (v) => `€${v}` });

        expect(handles.length).toBe(2);
        expect([handles[0].getAttribute("role"), handles[0].getAttribute("aria-label"), handles[1].getAttribute("aria-valuetext")]).toEqual(["slider", "van", "€100"]);
        expect(min.classList.contains("gaffer-search-range-input--enhanced")).toBe(true);
    });

    test("keys move a handle, write the input and fire change; handles never cross", () => {
        const { min, max, changes, handles } = slider();

        key(handles[0], "ArrowRight");
        key(handles[0], "PageUp");
        expect([min.value, changes]).toEqual(["11", ["min", "min"]]);

        key(handles[1], "Home");
        expect(max.value).toBe("11");
        key(handles[0], "End");
        expect(min.value).toBe("11");
    });

    test("syncFromStats and resetIfUnset write the inputs without change events, and report to onDrag", () => {
        const { s, min, max, shown, changes, handles } = slider();

        s.syncFromStats({ min: 10.4, max: 89.2 });
        expect([min.value, max.value, shown.at(-1), changes]).toEqual(["10", "90", [10, 90], []]);

        key(handles[0], "ArrowRight");
        s.syncFromStats({ min: 20, max: 50 });
        expect([min.value, max.value]).toEqual(["20", "50"], "a range the user set is clamped, not replaced");

        s.resetIfUnset({ queryChanged: true });
        expect([min.value, max.value, shown.at(-1)]).toEqual(["20", "50", [20, 50]]);
    });

    test("follows inputs changed by something else, and cleans up", () => {
        const { s, min, handles } = slider();

        min.value = "40";
        min.dispatchEvent(new Event("change"));
        expect(handles[0].getAttribute("aria-valuenow")).toBe("40");

        s.destroy();
        expect([document.querySelectorAll(".gaffer-search-range-track").length, min.classList.contains("gaffer-search-range-input--enhanced")]).toEqual([0, false]);
    });
});
