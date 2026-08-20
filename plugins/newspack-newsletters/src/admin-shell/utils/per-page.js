/**
 * Items-per-page constants shared by the DataView list screens.
 *
 * `PER_PAGE_ALL` is a client-side sentinel: "All" fetches
 * `FETCH_ALL_CHUNK_SIZE` rows at a time and concatenates (see
 * `use-collection-data.js`).
 *
 * The two ceilings below are deliberately different. `MAX_SELECTABLE_PER_PAGE`
 * bounds what a user can pick and store; `FETCH_ALL_CHUNK_SIZE` bounds
 * what one request of the "All" walk asks for, and is larger because
 * each round trip costs a full WordPress bootstrap while the rows
 * themselves are cheap.
 */

export const PER_PAGE_ALL = -1;

// Mirrors `Admin_Shell_Preferences::PER_PAGE_MAX`, which validates
// stored preferences — change both together.
export const MAX_SELECTABLE_PER_PAGE = 100;

// Mirrors `Admin_Shell_Collection_Params::MAX_PER_PAGE`, the ceiling
// those collections accept — change both together.
export const FETCH_ALL_CHUNK_SIZE = 1000;

// Caps the "All" walk so a very large site can't lock the tab with an
// unvirtualised table.
export const FETCH_ALL_MAX_ITEMS = 10000;

// DataViews' stock `perPageSizes` plus "All".
export const DEFAULT_PER_PAGE_OPTIONS = [ 10, 20, 50, 100, PER_PAGE_ALL ];

export const isFetchAllPerPage = value => value === PER_PAGE_ALL;

export const isValidPerPage = value => Number.isInteger( value ) && ( value === PER_PAGE_ALL || ( value >= 1 && value <= MAX_SELECTABLE_PER_PAGE ) );
