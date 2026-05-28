<?php
/**
 * Newspack Ads GAM Integration.
 *
 * Implements support for custom targeting key-val pairs for each site in the
 * network.
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub;

use Newspack_Network\Site_Role;
use Newspack_Network\Debugger;

use Newspack_Ads\Providers\GAM_Model;
use Newspack_Ads\Providers\GAM\Api\Targeting_Keys;

/**
 * Integration class for Newspack Ads GAM support.
 */
final class Newspack_Ads_GAM {
	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'newspack_ads_setup_gam', [ __CLASS__, 'create_targeting_keys' ] );
		add_action( 'newspack_network_node_saved', [ __CLASS__, 'create_targeting_keys' ], 1 );
	}

	/**
	 * Create targeting keys.
	 */
	public static function create_targeting_keys() {
		if ( ! Site_Role::is_hub() ) {
			return;
		}
		if ( ! class_exists( 'Newspack_Ads\Providers\GAM_Model' ) ) {
			return;
		}
		if ( ! class_exists( 'Newspack_Ads\Providers\GAM\Api\Targeting_Keys' ) ) {
			return;
		}
		$api = GAM_Model::get_api();
		if ( ! $api || is_wp_error( $api ) ) {
			Debugger::log( 'Error adding GAM targeting keys: GAM API is not available.' );
			return;
		}
		$nodes     = Nodes::get_all_nodes();
		$node_urls = array_map(
			function( $node ) {
				return Targeting_Keys::sanitize_url( $node->get_url() );
			},
			$nodes
		);
		$urls      = array_merge( [ Targeting_Keys::sanitize_url( \get_bloginfo( 'url' ) ) ], $node_urls );
		$api->targeting_keys->create_targeting_key( 'site', $urls, 'PREDEFINED', 'CUSTOM_DIMENSION' );
		Debugger::log( 'Updated GAM targeting keys.' );
	}
}
