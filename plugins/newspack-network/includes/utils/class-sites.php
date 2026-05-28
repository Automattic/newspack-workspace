<?php
/**
 * Newspack Network Sites methods.
 *
 * @package Newspack
 */

namespace Newspack_Network\Utils;

use Newspack_Network\Site_Role;
use Newspack_Network\Hub\Nodes;
use Newspack_Network\Hub\Node as Hub_Node;
use Newspack_Network\Node\Settings as Node_Settings;

/**
 * Class to get information about the sites in the network.
 *
 * Helper methods to get sites lists and info in an easy way.
 *
 * This is specially useful for third party integration that wants to get a list of the sites in the network.
 * With these methods, you can get that lists without worrying if you are in a hub or a node and how the data is stored.
 */
class Sites {

	/**
	 * Get all sites
	 *
	 * @return array Array of arrays with name and url properties.
	 */
	public static function get_all_sites() {
		return array_merge( [ self::get_hub() ], self::get_nodes() );
	}

	/**
	 * Get all sites but the current one
	 *
	 * @return array Array of arrays with name and url properties.
	 */
	public static function get_all_sites_without_current() {
		$sites = self::get_all_sites();
		return array_filter(
			$sites,
			function( $site ) {
				return $site['url'] !== get_bloginfo( 'url' );
			}
		);
	}

	/**
	 * Get the hub info
	 *
	 * @return array Array with name and url properties.
	 */
	public static function get_hub() {
		if ( Site_Role::is_hub() ) {
			$name = get_bloginfo( 'name' );
			$url = get_bloginfo( 'url' );
		}

		if ( Site_Role::is_node() ) {
			$url = Node_Settings::get_hub_url();
			$name = get_option( 'newspack_network_hub_name' );
			if ( empty( $name ) ) {
				$name = 'Hub';
			}
		}

		return [
			'name' => $name ?? '',
			'url'  => $url ?? '',
		];
	}

	/**
	 * Get the nodes info
	 *
	 * @return array Array of arrays with name and url properties.
	 */
	public static function get_nodes() {
		$sites = [];

		if ( Site_Role::is_hub() ) {
			$nodes = Nodes::get_all_nodes();
			foreach ( $nodes as $node ) {
				$sites[] = [
					'name' => $node->get_name(),
					'url'  => $node->get_url(),
				];
			}
		}

		if ( Site_Role::is_node() ) {
			$nodes_data = get_option( Hub_Node::HUB_NODES_SYNCED_OPTION, [] );
			foreach ( $nodes_data as $node_data ) {
				$sites[] = [
					'name' => $node_data['title'],
					'url'  => $node_data['url'],
				];
			}

			// Add current node.
			$sites[] = [
				'name' => get_bloginfo( 'name' ),
				'url'  => get_bloginfo( 'url' ),
			];
		}

		return $sites;
	}

	/**
	 * Generates a collection of bookmarks for a site
	 *
	 * @param  string $url The URL of the site.
	 * @return array Array of arrays with label and url properties.
	 */
	public static function generate_bookmarks( $url ) {
		$base_url = trailingslashit( $url );

		return [
			[
				'label' => __( 'Dashboard', 'newspack-network' ),
				'url'   => $base_url . 'wp-admin/',
			],
			[
				'label' => 'Newspack',
				'url'   => $base_url . 'wp-admin?page=newspack',
			],
			[
				'label' => 'WooCommerce',
				'url'   => $base_url . 'wp-admin/admin.php?page=wc-admin',
			],
			[
				'label' => __( 'Posts', 'newspack-network' ),
				'url'   => $base_url . 'wp-admin/edit.php',
			],
			[
				'label' => __( 'Users', 'newspack-network' ),
				'url'   => $base_url . 'wp-admin/users.php',
			],
			[
				'label' => __( 'Plugins', 'newspack-network' ),
				'url'   => $base_url . 'wp-admin/plugins.php',
			],
			[
				'label' => __( 'Settings', 'newspack-network' ),
				'url'   => $base_url . 'wp-admin/options-general.php',
			],
		];
	}
}
