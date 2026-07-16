/**
 * Ambient declaration for the localized data backing the GAM header-bidding
 * settings screen, set via `wp_localize_script()` in
 * `includes/integrations/class-bidding-gam.php`.
 */
interface Window {
	newspack_ads_bidding_gam: {
		network_code: string;
		lica_batch_size: number;
	};
}
