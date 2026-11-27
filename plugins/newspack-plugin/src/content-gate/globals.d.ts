/**
 * Ambient declarations for the front-end content-gate globals localized by PHP.
 * Global-script form (no top-level imports/exports), so every declaration lands
 * in the global scope and the Window merge applies globally.
 */

interface Window {
	/**
	 * `newspack_content_gate`. Localized on the gate preview script with the
	 * query-param names to carry across navigation. Absent outside a gate
	 * preview, so every read guards with `?.`.
	 */
	newspack_content_gate?: {
		preview_param_names?: string[];
		[ key: string ]: unknown;
	};
}
