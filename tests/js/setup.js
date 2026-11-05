// A browser-like global environment (window, document, FormData, history, CustomEvent) for the
// search modules. The tests reach every module's functions through one object.
import { GlobalRegistrator } from "@happy-dom/global-registrator";

GlobalRegistrator.register({ url: "https://shop.test/winkel/" });
window.GafferSearch = {
    ...(await import("../../resources/js/search/helpers.js")),
    ...(await import("../../resources/js/search/alpine-search.js")),
    ...(await import("../../resources/js/search/range-slider.js")),
    ...(await import("../../resources/js/search/archive.js")),
    ...(await import("../../resources/js/search/counter.js")),
};
