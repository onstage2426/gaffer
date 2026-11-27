import Alpine from "alpinejs";
import collapse from "@alpinejs/collapse";
import focus from "@alpinejs/focus";
import { modal } from "./modal.js";
import { slider } from "./components/slider.js";
import { productGallery } from "./components/product-gallery.js";
import { productGalleryModal } from "./components/product-gallery-modal.js";
import { searchbar } from "./components/searchbar.js";
import { cartBadge } from "@gaffer/cart.js";

// Global for Alpine's devtools and inline x-init code
window.Alpine = Alpine;

Alpine.plugin(collapse);
Alpine.plugin(focus);

// Every component the templates use in x-data
Alpine.data("modal", (name) => modal(name));
Alpine.data("slider", (options) => slider(options));
Alpine.data("productGallery", productGallery);
Alpine.data("productGalleryModal", productGalleryModal);
Alpine.data("searchbar", searchbar);
Alpine.data("cartBadge", cartBadge);

export { Alpine };
