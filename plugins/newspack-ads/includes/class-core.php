<?php
/**
 * Newspack Ads set up
 *
 * @package Newspack
 */

namespace Newspack_Ads;

defined( 'ABSPATH' ) || exit;

/**
 * Main Newspack Ads Class.
 */
final class Core {

	/**
	 * The single instance of the class.
	 *
	 * @var Newspack_Ads\Core
	 */
	protected static $instance = null;

	/**
	 * Main Newspack Ads Instance.
	 * Ensures only one instance of Newspack Ads is loaded or can be loaded.
	 *
	 * @return Newspack_Ads\Core Newspack Ads - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->includes();

		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
		register_activation_hook( NEWSPACK_ADS_PLUGIN_FILE, [ __CLASS__, 'activation_hook' ] );
	}

	/**
	 * Activation Hook
	 */
	public static function activation_hook() {
		do_action( 'newspack_ads_activation_hook' );
	}

	/**
	 * Enqueue front-end styles.
	 */
	public static function enqueue_scripts() {
		if ( ! newspack_ads_should_show_ads() ) {
			return;
		}
		$asset_file = require_once dirname( NEWSPACK_ADS_PLUGIN_FILE ) . '/dist/frontend.asset.php';

		\wp_enqueue_script(
			'newspack-ads-frontend',
			plugins_url( '../dist/frontend.js', __FILE__ ),
			[],
			$asset_file['version'],
			true
		);

		\wp_register_style(
			'newspack-ads-frontend',
			plugins_url( '../dist/frontend.css', __FILE__ ),
			[],
			$asset_file['version']
		);
		\wp_style_add_data( 'newspack-ads-frontend', 'rtl', 'replace' );
		\wp_enqueue_style( 'newspack-ads-frontend' );
	}

	/**
	 * Include required core files used in admin and on the frontend.
	 * e.g. include_once NEWSPACK_ADS_ABSPATH . 'includes/foo.php';
	 */
	private function includes() {
		include_once NEWSPACK_ADS_ABSPATH . '/includes/utils.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/providers/interface-provider.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/providers/class-provider.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/providers/gam/class-gam-provider.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/providers/broadstreet/class-broadstreet-provider.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/class-settings.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/class-custom-label.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/class-fixed-height.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/class-providers.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/class-bidding.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/bidders/class-medianet.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/bidders/class-openx.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/bidders/class-pubmatic.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/bidders/class-sovrn.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/integrations/class-bidding-gam.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/class-placements.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/class-sidebar-placements.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/integrations/class-scaip.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/integrations/class-complianz.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/integrations/class-ad-refresh-control.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/blocks/class-ad-unit-block.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/blocks/class-tabs-block.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/blocks/class-tabs-item-block.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/class-widget.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/customizer/class-customizer.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/class-suppression.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/media-kit/class-media-kit.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/functions.php';
		include_once NEWSPACK_ADS_ABSPATH . '/includes/integrations/class-side-rail-placements.php';
	}

	/**
	 * Get the URL for the Newspack Ads plugin directory.
	 *
	 * @param string $path Optional path to append.
	 * @return string URL
	 */
	public static function plugin_url( $path ) {
		$path = $path ? trim( $path ) : '';
		if ( $path && strpos( '/', $path ) !== 1 ) {
			$path = '/' . $path;
		}
		return \untrailingslashit( plugins_url( '/', NEWSPACK_ADS_PLUGIN_FILE ) ) . $path;
	}
}
Core::instance();
