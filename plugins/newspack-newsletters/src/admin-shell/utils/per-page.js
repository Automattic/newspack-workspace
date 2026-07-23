/**
 * Items-per-page constants shared by the DataView list screens.
 *
 * `PER_PAGE_ALL` is a client-side sentinel — the REST API caps
 * `per_page` at 100, so "All" fetches `FETCH_ALL_CHUNK_SIZE` at a time
 * and concatenates (see `use-collection-data.js`).
 */

export const PER_PAGE_ALL = -1;

// Mirrors `Admin_Shell_Preferences::PER_PAGE_MAX` (the REST cap the
// server validates stored preferences against) — change both together.
export const FETCH_ALL_CHUNK_SIZE = 100;

// Caps the "All" walk so a very large site can't lock the tab with an
// unvirtualised table.
export const FETCH_ALL_MAX_ITEMS = 10000;

// DataViews' stock `perPageSizes` plus "All".
export const DEFAULT_PER_PAGE_OPTIONS = [ 10, 20, 50, 100, PER_PAGE_ALL ];

export const isFetchAllPerPage = value => value === PER_PAGE_ALL;

export const isValidPerPage = value => Number.isInteger( value ) && ( value === PER_PAGE_ALL || ( value >= 1 && value <= FETCH_ALL_CHUNK_SIZE ) );
