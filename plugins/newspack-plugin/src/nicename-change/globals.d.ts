/**
 * Ambient declarations for the nicename-change entry.
 *
 * Global-script form (no top-level imports/exports) so the declarations land in
 * the global scope and merge with `src/shared/globals.d.ts`.
 */

/**
 * Payload localized by Nicename_Change_UI::enqueue_scripts() as
 * `newspack_change_nicename_params`.
 */
declare const newspack_change_nicename_params: {
	ajax_url: string;
	empty_message: string;
};
