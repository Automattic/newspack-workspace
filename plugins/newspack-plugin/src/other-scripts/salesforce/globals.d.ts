/**
 * Ambient declarations for the `salesforce` entry. Global-script form
 * (no top-level imports/exports) so every declaration lands in the global scope.
 */

/**
 * Data localized by Salesforce (includes/class-salesforce.php).
 * All values are top-level scalars, which wp_localize_script() casts to strings.
 */
declare const newspack_salesforce_data: {
	/** REST API base URL. */
	base_url: string;
	/** The order (shop_order post) ID. */
	order_id: string;
	/** The Salesforce instance URL. */
	salesforce_url: string;
	/** REST API nonce. */
	nonce: string;
};
