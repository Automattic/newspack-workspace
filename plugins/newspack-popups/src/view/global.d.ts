/**
 * Unit-local window globals for the reader-facing view runtime (`src/view` and
 * its `analytics`, `utils`, `merge-tags` subfolders, plus the admin
 * preview-toggle script `src/view/admin.js`). Localized by
 * `Newspack_Popups_Inserter::enqueue_scripts()`.
 *
 * This is a global script (no top-level imports/exports); inline `import()`
 * types only.
 */

/**
 * A single criteria assignment within a segment's `criteria` array, as read by
 * `src/criteria/utils`'s `Criteria.matches()`. Structurally compatible with
 * that module's `SegmentConfig` (own source of truth for the matching
 * contract) -- not re-imported here to keep this file a global script.
 */
interface PopupsSegmentCriteriaItem {
	criteria_id: string;
	value?: unknown;
	[ key: string ]: unknown;
}

/**
 * A segment definition as localized to the front-end by
 * `Newspack_Popups_Inserter::enqueue_scripts()` (mirrors the shape built by
 * `Newspack_Popups_Segmentation::get_segments()`).
 *
 * Carries an index signature (rather than relying on structural inference) so
 * it satisfies the cross-plugin `NewspackRasSegment` shape when passed to
 * `ras.segments.register()`.
 */
interface PopupsSegment {
	name?: string;
	priority: number;
	criteria: PopupsSegmentCriteriaItem[];
	[ key: string ]: unknown;
}

/**
 * Localized data for the reader-facing view script (`newspack-popups-view`).
 * Fields are optional even though `Newspack_Popups_Inserter::enqueue_scripts()`
 * always sends `debug`/`has_disabled_prompts`: `segments.test.ts` seeds this
 * global with `{}` to isolate the segmentation logic under test.
 */
interface NewspackPopupsViewConfig {
	debug?: boolean;
	has_disabled_prompts?: boolean;
	segments?: Record< string, PopupsSegment >;
	donor_landing_page?: number | string;
}

/** Localized data for the admin prompts-visibility preview-toggle script. */
interface NewspackPopupsAdminConfig {
	label_visible: string;
	label_hidden: string;
}

declare const newspack_popups_view: NewspackPopupsViewConfig;
declare const newspack_popups_admin: NewspackPopupsAdminConfig;

/**
 * Sanitized popup/prompt metadata keyed by prompt ID or title, printed by
 * `Newspack_Popups_Data_Api::print_popups_data()` for GA4 event payloads. All
 * values are flattened/stringified server-side by `prepare_popup_params_for_ga()`.
 */
type NewspackPopupsGaData = Record< string, string | number >;

/**
 * Not declared with `Window['newspackPopupsData']` because it's referenced as
 * a bare identifier (no `window.` prefix) by `src/view/utils/analytics.ts`;
 * may legitimately be absent if no prompts were rendered on the page.
 */
declare const newspackPopupsData: Record< string, NewspackPopupsGaData > | undefined;

/**
 * Google Analytics `gtag.js` global. Not always present (only loaded when the
 * site has GA configured) -- callers guard with `typeof gtag === 'function'`.
 */
declare const gtag: ( ( ...args: unknown[] ) => void ) | undefined;

/**
 * A rendered prompt element (`.newspack-popup-container`). `overlayId` is a
 * custom property assigned at runtime (not a DOM property) to track the
 * prompt's registration in the RAS overlay registry.
 */
type PromptElement = HTMLElement & { overlayId?: string };

interface Window {
	/** Debug bag populated when `newspack_popups_view.debug` is true. */
	newspack_popups_debug?: Record< string, unknown >;
	/**
	 * Declared here too (in addition to the bare `newspack_popups_view` const
	 * above) so `segments.test.ts`'s `window.newspack_popups_view = {...}`
	 * property assignment type-checks; both refer to the same global binding.
	 */
	newspack_popups_view?: NewspackPopupsViewConfig;
}
