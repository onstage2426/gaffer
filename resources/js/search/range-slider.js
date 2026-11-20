/**
 * An optional visual enhancement over two real form <input> fields, built on
 * one shared pointer-hit-surface.
 *
 * The two inputs are the source of truth and the no-JS fallback — plain
 * number/range fields, submitted normally as part of the form. This engine
 * only makes them nicer to drag: it reads their initial value/bounds, draws
 * a rail/fill/handle visual, and on release writes back to the real inputs
 * and dispatches real input/change events — so a form-level `change`
 * listener (wired once by the theme) is what actually triggers a search.
 * The slider itself knows nothing about searching.
 *
 * Two overlaid native <input type="range"> elements — i.e. making the
 * *visual* handles themselves be the form fields — were tried first and
 * produced two real bugs: binding each thumb's min/max attribute to the
 * other's live value gave the two inputs different coordinate systems, and
 * even after fixing that, whichever thumb was physically on top always won
 * a click where they visually overlapped — no CSS/z-index trick fixes that,
 * because the browser resolves the click by DOM stacking order, not by
 * which handle's *value* is actually closer to the click.
 *
 * This engine's own visual handles (plain divs, not form fields) resolve
 * every pointerdown by computing the clicked value and picking whichever
 * handle's current value is numerically nearer to it — the same approach
 * real dual-handle sliders (noUiSlider, react-aria, Radix's Slider) use.
 * That structurally cannot be won by paint order.
 */

function clamp(v, lo, hi) {
    return Math.min(Math.max(v, lo), hi);
}

const KEY_STEP = { ArrowRight: 1, ArrowUp: 1, ArrowLeft: -1, ArrowDown: -1 };

/**
 * @param {HTMLInputElement} minInput - real form field, e.g. <input type="number" name="filter[price][min]">
 * @param {HTMLInputElement} maxInput
 * @param {{
 *   track?: HTMLElement,          // visual rail container; auto-created and inserted between the inputs if omitted
 *   min?: number, max?: number,   // bounds; default to minInput.min / maxInput.max
 *   step?: number,
 *   ariaLabels?: [string, string],
 *   formatValue?: (v: number) => string,           // for aria-valuetext
 *   onDrag?: (values: [number, number]) => void,    // the shown values changed (drag, keys, resetIfUnset(), syncFromStats()) — for a live label, not a search trigger
 * }} [options]
 * @returns {{
 *   setBounds(min: number, max: number): void,
 *   resetIfUnset(opts?: { queryChanged?: boolean }): void,
 *   syncFromStats(stats?: { min: number, max: number }): void,
 *   destroy(): void,
 * }}
 */
function createDualRangeSlider(minInput, maxInput, options = {}) {
    const {
        track = document.createElement("div"),
        step: initStep = 1,
        ariaLabels = ["minimum", "maximum"],
        formatValue,
        onDrag = () => {},
    } = options;

    let bounds = {
        min: options.min ?? (minInput.min !== "" ? Number(minInput.min) : 0),
        max: options.max ?? (maxInput.max !== "" ? Number(maxInput.max) : 100),
        step: initStep,
    };
    let vals = [clamp(Number(minInput.value) || bounds.min, bounds.min, bounds.max), clamp(Number(maxInput.value) || bounds.max, bounds.min, bounds.max)];

    // Tracks a search-lifecycle concept (has the user picked a custom range
    // for the *current* query, has a first facetStats response arrived yet)
    // — set by resetIfUnset()/syncFromStats() below, not by anything visual.
    let userSet = false;
    let ready = false;

    minInput.classList.add("gaffer-search-range-input--enhanced");
    maxInput.classList.add("gaffer-search-range-input--enhanced");
    if (!options.track) minInput.after(track);

    track.classList.add("gaffer-search-range-track");
    const rail = document.createElement("div");
    rail.className = "gaffer-search-range-rail";
    const fill = document.createElement("div");
    fill.className = "gaffer-search-range-fill";
    const handles = [0, 1].map((i) => {
        const h = document.createElement("div");
        h.className = "gaffer-search-range-thumb";
        h.setAttribute("role", "slider");
        h.setAttribute("tabindex", "0");
        h.setAttribute("aria-label", ariaLabels[i]);
        return h;
    });
    track.append(rail, fill, handles[0], handles[1]);

    function valueToPercent(v) {
        const span = bounds.max - bounds.min || 1;
        return clamp(((v - bounds.min) / span) * 100, 0, 100);
    }

    function percentToValue(p) {
        const span = bounds.max - bounds.min;
        const raw = bounds.min + (clamp(p, 0, 100) / 100) * span;
        const stepped = Math.round(raw / bounds.step) * bounds.step;
        return clamp(stepped, bounds.min, bounds.max);
    }

    function clientXToValue(clientX) {
        const rect = track.getBoundingClientRect();
        const pct = rect.width ? ((clientX - rect.left) / rect.width) * 100 : 0;
        return percentToValue(pct);
    }

    function render() {
        const [lo, hi] = vals;
        fill.style.left = `${valueToPercent(lo)}%`;
        fill.style.right = `${100 - valueToPercent(hi)}%`;
        handles.forEach((h, i) => {
            const v = vals[i];
            h.style.left = `${valueToPercent(v)}%`;
            h.setAttribute("aria-valuemin", String(bounds.min));
            h.setAttribute("aria-valuemax", String(bounds.max));
            h.setAttribute("aria-valuenow", String(v));
            if (formatValue) h.setAttribute("aria-valuetext", formatValue(v));
        });
    }

    // which: "min" | "max". Clamps against the other handle's current value
    // so the two can never cross.
    function setActiveValue(which, v) {
        if (which === "min") {
            vals[0] = Math.min(v, vals[1]);
        } else {
            vals[1] = Math.max(v, vals[0]);
        }
    }

    // Writes the current values back to the real inputs and dispatches real
    // input/change events — this is the only place the slider talks to the
    // form. A no-op per-input if the value didn't actually change, so we
    // never fire spurious events (and never re-trigger our own
    // onExternalChange listener below).
    function commitToInputs() {
        userSet = true;
        if (Number(minInput.value) !== vals[0]) {
            minInput.value = String(vals[0]);
            minInput.dispatchEvent(new Event("input", { bubbles: true }));
            minInput.dispatchEvent(new Event("change", { bubbles: true }));
        }
        if (Number(maxInput.value) !== vals[1]) {
            maxInput.value = String(vals[1]);
            maxInput.dispatchEvent(new Event("input", { bubbles: true }));
            maxInput.dispatchEvent(new Event("change", { bubbles: true }));
        }
    }

    function onPointerDown(e) {
        if (e.button !== 0) return;
        e.preventDefault();

        const clickValue = clientXToValue(e.clientX);
        const which = Math.abs(clickValue - vals[0]) <= Math.abs(clickValue - vals[1]) ? "min" : "max";
        const handleEl = handles[which === "min" ? 0 : 1];

        handleEl.focus();
        handleEl.setPointerCapture(e.pointerId);
        setActiveValue(which, clickValue);
        render();
        onDrag([...vals]);

        const onMove = (moveEvent) => {
            setActiveValue(which, clientXToValue(moveEvent.clientX));
            render();
            onDrag([...vals]);
        };
        const onUp = () => {
            handleEl.releasePointerCapture(e.pointerId);
            handleEl.removeEventListener("pointermove", onMove);
            handleEl.removeEventListener("pointerup", onUp);
            handleEl.removeEventListener("pointercancel", onUp);
            commitToInputs();
        };
        handleEl.addEventListener("pointermove", onMove);
        handleEl.addEventListener("pointerup", onUp);
        handleEl.addEventListener("pointercancel", onUp);
    }

    function onKeyDown(which, e) {
        const i = which === "min" ? 0 : 1;
        let v = vals[i];
        if (e.key in KEY_STEP) {
            v += KEY_STEP[e.key] * bounds.step;
        } else if (e.key === "PageUp") {
            v += bounds.step * 10;
        } else if (e.key === "PageDown") {
            v -= bounds.step * 10;
        } else if (e.key === "Home") {
            v = which === "min" ? bounds.min : vals[0];
        } else if (e.key === "End") {
            v = which === "min" ? vals[1] : bounds.max;
        } else {
            return;
        }
        e.preventDefault();
        setActiveValue(which, v);
        render();
        onDrag([...vals]);
        commitToInputs(); // keyboard has no separate "release" event to defer to
    }

    // Keeps the visual in sync if something else changes the inputs
    // directly (autofill, another script, browser form restore). Guarded
    // against the loop where commitToInputs()'s own dispatched events land
    // right back here: by the time they fire, the input's value already
    // equals vals[i], so the comparison below is a no-op.
    function onExternalChange(which) {
        const i = which === "min" ? 0 : 1;
        const inputEl = which === "min" ? minInput : maxInput;
        const v = clamp(Number(inputEl.value), bounds.min, bounds.max);
        if (v === vals[i]) return;
        setActiveValue(which, v);
        render();
    }

    const onMinExternalChange = () => onExternalChange("min");
    const onMaxExternalChange = () => onExternalChange("max");

    track.addEventListener("pointerdown", onPointerDown);
    handles[0].addEventListener("keydown", (e) => onKeyDown("min", e));
    handles[1].addEventListener("keydown", (e) => onKeyDown("max", e));
    minInput.addEventListener("change", onMinExternalChange);
    maxInput.addEventListener("change", onMaxExternalChange);

    render();

    // Writes vals directly to the real inputs and re-renders, without
    // dispatching input/change — used by resetIfUnset()/syncFromStats() for
    // changes that originate from the slider's own search-lifecycle state,
    // not a user drag/keypress, so they must never re-trigger a search.
    // onDrag still hears about it: a live label shows these values too.
    function writeInputs() {
        minInput.value = String(vals[0]);
        maxInput.value = String(vals[1]);
        render();
        onDrag([...vals]);
    }

    return {
        setBounds(newMin, newMax) {
            bounds = { ...bounds, min: newMin, max: newMax };
            minInput.min = String(newMin);
            minInput.max = String(newMax);
            maxInput.min = String(newMin);
            maxInput.max = String(newMax);
            vals = [clamp(vals[0], newMin, newMax), clamp(vals[1], newMin, newMax)];
            render();
        },

        /**
         * Call right before issuing a new search request. Clears `userSet`
         * when the query text changed; if the user hasn't touched the
         * slider (for the current query), resets vals back to the full
         * facet range — directly on the real inputs, so the request that's
         * about to be built from FormData actually carries the reset
         * values, not a stale filter left over from the previous query.
         */
        resetIfUnset({ queryChanged = false } = {}) {
            if (queryChanged) userSet = false;
            if (userSet || !ready) return;
            vals = [bounds.min, bounds.max];
            writeInputs();
        },

        /**
         * Call after a search response arrives, with that field's
         * facetStats entry ({ min, max } over the current result set).
         * No-op if `stats` is falsy. Initializes the range on the first
         * call. On later calls: snaps to the new bounds if the user hasn't
         * set a custom range, otherwise clamps their existing selection to
         * stay inside the new bounds instead of overwriting it.
         */
        syncFromStats(stats) {
            if (!stats) return;
            const newMin = Math.floor(stats.min ?? 0);
            const newMax = Math.ceil(stats.max ?? 0);

            bounds = { ...bounds, min: newMin, max: newMax };
            minInput.min = String(newMin);
            minInput.max = String(newMax);
            maxInput.min = String(newMin);
            maxInput.max = String(newMax);

            if (!ready) {
                vals = [newMin, newMax];
                ready = true;
            } else if (!userSet) {
                vals = [newMin, newMax];
            } else {
                vals = [clamp(vals[0], newMin, newMax), clamp(vals[1], newMin, newMax)];
            }
            writeInputs();
        },

        destroy() {
            track.removeEventListener("pointerdown", onPointerDown);
            minInput.removeEventListener("change", onMinExternalChange);
            maxInput.removeEventListener("change", onMaxExternalChange);
            minInput.classList.remove("gaffer-search-range-input--enhanced");
            maxInput.classList.remove("gaffer-search-range-input--enhanced");
            track.remove();
        },
    };
}

export { createDualRangeSlider };
