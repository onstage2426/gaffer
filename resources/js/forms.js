/**
 * Gaffer's form JavaScript: shrinks photos in a form's file inputs before htmx sends it, so a
 * form with several phone photos (3 to 8 MB each) sends a few hundred KB per photo. Import it
 * once (through Vite, with the "@gaffer" alias like search.js) and call it:
 *
 *   import { shrinkUploads } from "@gaffer/forms.js";
 *   shrinkUploads();
 *
 * An input opts in with the longest side in pixels:
 *
 *   <input type="file" name="files[fotos][]" multiple accept="image/*,.pdf" data-gaffer-resize="2000">
 *
 * A JPEG, PNG, WebP or HEIC photo larger than that is drawn at that size and sent as JPEG
 * (quality 0.85, the name's extension becomes .jpg: allow jpg in Gravity Forms). HEIC is always
 * converted. Other files (PDFs), smaller photos and photos the browser can't decode go as they
 * are. Re-encoding drops the photo's metadata (location included).
 *
 * Photos are shrunk as soon as they're chosen (the input's change event). While that runs the
 * form has aria-busy="true", and a submit waits for it: the submit event is held before htmx
 * sees it and sent again (requestSubmit) once the files are shrunk. That works the same with
 * htmx 2 and htmx 4 (htmx 4 reads the form's files before any event that could wait).
 */

const QUALITY = 0.85;
const IMAGES = ["image/jpeg", "image/png", "image/webp", "image/heic", "image/heif"];
const ALWAYS = ["image/heic", "image/heif"];
const INPUT = "input[type=file][data-gaffer-resize]";

/**
 * Listens on the page: shrinks opted-in inputs when files are chosen, and holds a form's
 * submit until its inputs are done.
 *
 * @param {Document|HTMLElement} root
 * @param {(file: File, max: number) => Promise<File>} shrink how one file is shrunk (tests)
 */
export function shrinkUploads(root = document, shrink = shrinkImage) {
    /** @type {WeakMap<HTMLFormElement, Set<Promise<void>>>} */
    const busy = new WeakMap();

    root.addEventListener(
        "change",
        (event) => {
            const input = event.target;
            if (!input?.matches?.(INPUT) || !input.files || input.files.length === 0) {
                return;
            }
            const form = input.form;
            const job = shrinkInput(input, shrink).catch(() => {}); // send what there is: the server checks sizes anyway
            if (!form) {
                return;
            }
            const jobs = busy.get(form) ?? new Set();
            busy.set(form, jobs);
            jobs.add(job);
            form.setAttribute("aria-busy", "true");
            job.finally(() => {
                jobs.delete(job);
                if (jobs.size === 0) {
                    form.removeAttribute("aria-busy");
                }
            });
        },
        true,
    );

    // Capture on the root: runs before htmx's own submit listener on the form.
    root.addEventListener(
        "submit",
        (event) => {
            const form = event.target;
            const jobs = form instanceof HTMLFormElement ? busy.get(form) : null;
            if (!jobs || jobs.size === 0) {
                return;
            }
            event.preventDefault();
            event.stopImmediatePropagation();
            if (form.dataset.gafferHeld === "1") {
                return; // already waiting: one send when done
            }
            form.dataset.gafferHeld = "1";
            const submitter = event.submitter ?? null;
            Promise.allSettled([...jobs]).then(() => {
                delete form.dataset.gafferHeld;
                if (form.isConnected) {
                    submitter && submitter.form === form ? form.requestSubmit(submitter) : form.requestSubmit();
                }
            });
        },
        true,
    );
}

/**
 * Replaces an input's files with shrunk ones (one at a time: a phone's memory).
 *
 * @param {HTMLInputElement} input
 * @param {(file: File, max: number) => Promise<File>} shrink
 */
export async function shrinkInput(input, shrink = shrinkImage) {
    const max = parseInt(input.dataset.gafferResize ?? "", 10);
    if (!(max > 0) || typeof DataTransfer === "undefined") {
        return;
    }
    const files = [...input.files];
    const shrunk = [];
    for (const file of files) {
        shrunk.push(await shrink(file, max).catch(() => file));
    }
    if (shrunk.every((file, i) => file === files[i]) || input.files.length !== files.length || [...input.files].some((file, i) => file !== files[i])) {
        return; // nothing changed, or the visitor chose other files meanwhile (their change shrinks those)
    }
    const transfer = new DataTransfer();
    shrunk.forEach((file) => transfer.items.add(file));
    input.files = transfer.files; // fires no change event
}

/**
 * One photo at most `max` pixels on its longest side, as JPEG; the file itself when it isn't
 * a photo, is small enough, or would come out larger.
 *
 * @param {File} file
 * @param {number} max
 * @returns {Promise<File>}
 */
export async function shrinkImage(file, max) {
    if (!IMAGES.includes(file.type)) {
        return file;
    }
    const always = ALWAYS.includes(file.type);
    const bitmap = await createImageBitmap(file, { imageOrientation: "from-image" });
    try {
        const scale = Math.min(1, max / Math.max(bitmap.width, bitmap.height));
        if (scale === 1 && !always) {
            return file;
        }
        const canvas = document.createElement("canvas");
        canvas.width = Math.max(1, Math.round(bitmap.width * scale));
        canvas.height = Math.max(1, Math.round(bitmap.height * scale));
        const context = canvas.getContext("2d");
        context.fillStyle = "#fff"; // JPEG has no transparency: white, not black
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, "image/jpeg", QUALITY));
        if (!blob || (blob.size >= file.size && !always)) {
            return file;
        }
        const name = file.name.replace(/\.[^.]*$/, "") + ".jpg";

        return new File([blob], name, { type: "image/jpeg", lastModified: file.lastModified });
    } finally {
        bitmap.close?.();
    }
}
