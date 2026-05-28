<?php
/**
 * Newspack Network Utils methods.
 *
 * @package Newspack
 */

namespace Newspack_Network\Utils;

use Newspack_Network\Hub\Node as Hub_Node;
use Newspack_Network\Hub\Nodes as Hub_Nodes;
use Newspack_Network\Node\Settings;
use Newspack_Network\Site_Role;

/**
 * Network.
 */
class Network {
	/**
	 * Get all networked URLs - excluding url of the site where the function is called.
	 *
	 * Note that all urls have been run through untrailingslashit.
	 *
	 * @return array Array of networked URLs.
	 */
	public static function get_networked_urls(): array {
		if ( Site_Role::is_hub() ) {
			return array_map( fn( $node ) => untrailingslashit( $node->get_url() ), Hub_Nodes::get_all_nodes() );
		}
		$urls = [
			Settings::get_hub_url(),
			...array_map( fn( $node ) => $node['url'], get_option( Hub_Node::HUB_NODES_SYNCED_OPTION, [] ) ),
		];

		// Filter out empty values and apply untrailingslashit.
		return array_map( 'untrailingslashit', array_filter( $urls ) );
	}

	/**
	 * Check if a URL is networked.
	 *
	 * @param string $url URL to check.
	 *
	 * @return bool True if the URL is networked, false otherwise.
	 */
	public static function is_networked_url( string $url ): bool {
		return in_array( untrailingslashit( $url ), self::get_networked_urls(), true );
	}

	/**
	 * Get list of network URLs given a list of URLs.
	 *
	 * @param string[] $urls Array of URLs.
	 *
	 * @return string[] Array of networked URLs.
	 */
	public static function get_networked_urls_from_list( array $urls ): array {
		return array_intersect( array_map( 'untrailingslashit', $urls ), self::get_networked_urls() );
	}

	/**
	 * Get list of URLs that don't belong in the network given a list of URLs.
	 *
	 * @param string[] $urls Array of URLs.
	 *
	 * @return string[] Array of networked URLs.
	 */
	public static function get_non_networked_urls_from_list( array $urls ): array {
		return array_diff( array_map( 'untrailingslashit', $urls ), self::get_networked_urls() );
	}
}
