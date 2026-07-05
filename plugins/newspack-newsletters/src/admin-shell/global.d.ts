/**
 * Admin-shell-only members of the `newspackNewslettersAdmin` window global.
 *
 * The global itself (and `adminUrl` / `bundledMode` / `label`) is declared
 * unit-wide in `src/newspack-newsletters-globals.d.ts`. This file merges the
 * additional keys the admin shell reads (mount id, current page slug, CPT
 * slug) into that same `NewspackNewslettersAdmin` interface via declaration
 * merging, so there is a single source of truth for the global's shape.
 *
 * This is a global script (no top-level imports); inline `import()` types only.
 */

interface NewspackNewslettersAdmin {
	/** DOM id of the mount target. */
	mountId?: string;
	/** Current admin page slug (drives screen resolution). */
	currentPage?: string;
	/** Newsletters CPT slug. */
	cptSlug?: string;
}
