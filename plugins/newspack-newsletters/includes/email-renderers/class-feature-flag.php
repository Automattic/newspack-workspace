<?php
/**
 * Feature flag for the WooCommerce Email Editor renderer.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves whether the WC email-editor renderer is enabled for this site.
 */
class Feature_Flag {
	const OPTION = 'newspack_newsletters_use_woo_renderer';

	/**
	 * Whether the WC renderer is enabled. Constant overrides option; filter wins last.
	 *
	 * @return boolean
	 */
	public static function is_enabled() {
		$enabled = (bool) get_option( self::OPTION, false );
		if ( defined( 'NEWSPACK_NEWSLETTERS_WOO_RENDERER' ) ) {
			$enabled = (bool) constant( 'NEWSPACK_NEWSLETTERS_WOO_RENDERER' );
		}
		/**
		 * Filters whether the WC email-editor renderer is enabled.
		 *
		 * @param boolean $enabled Whether enabled.
		 */
		return (bool) apply_filters( 'newspack_newsletters_use_woo_renderer', $enabled );
	}
}
