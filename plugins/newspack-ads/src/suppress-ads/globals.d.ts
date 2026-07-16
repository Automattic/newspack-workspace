/**
 * Ambient declaration for the localized data backing the ad-suppression
 * document settings panel, set via `wp_localize_script()` in
 * `includes/class-suppression.php`.
 */
interface Window {
	newspackAdsSuppressAds?: {
		placements: Record< string, { name: string } >;
	};
}
