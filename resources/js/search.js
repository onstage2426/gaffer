/**
 * Gaffer's search JavaScript, for themes with the gaffer-search plugin. Import what a component
 * needs from here (through Vite: it's built into the theme's own bundle, locked with the theme's
 * Gaffer version):
 *
 *   import { archive, createAlpineSearch } from "../../vendor/onstage2426/gaffer/resources/js/search.js";
 *
 * The endpoint and token come from the server (Search::archive(), Search::searchbar()) as the
 * form's action and its hidden `token` input.
 */

export { archive } from "./search/archive.js";
export { createAlpineSearch, createFilterForm } from "./search/alpine-search.js";
export { createDualRangeSlider } from "./search/range-slider.js";
export { formatCurrency, getActiveFilters, getFacetOptions, getPageNumbers, removeFilter } from "./search/helpers.js";
