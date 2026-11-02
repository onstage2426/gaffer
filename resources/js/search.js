/**
 * Gaffer's search JavaScript, for themes with the gaffer-search plugin. Import what a component
 * needs from here (through Vite: it's built into the theme's own bundle, locked with the theme's
 * Gaffer version), with the alias "@gaffer" for vendor/onstage2426/gaffer/resources/js in the
 * theme's vite.config.js:
 *
 *   import { archive, createAlpineSearch } from "@gaffer/search.js";
 *
 * search/range-slider.css has starting styles for createDualRangeSlider().
 *
 * The endpoint and token come from the server (Search::archive(), Search::searchbar()) as the
 * form's action and its hidden `token` input.
 */

export { archive } from "./search/archive.js";
export { createAlpineSearch, createFilterForm } from "./search/alpine-search.js";
export { createDualRangeSlider } from "./search/range-slider.js";
export { formatCurrency, getActiveFilters, getFacetOptions, getPageNumbers, removeFilter } from "./search/helpers.js";
