<?php
/**
 * Admin shell — REST collection parameters.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Admin;

use Newspack_Newsletters;
use Newspack_Newsletters\Ads;
use Newspack_Newsletters_Layouts;

defined( 'ABSPATH' ) || exit;

/**
 * Raises the `per_page` ceiling on the collections the admin-shell list
 * screens read.
 *
 * Core caps `per_page` at 100, which is what makes the "All" option fan
 * out into one request per 100 rows. At that size the response itself is
 * cheap (roughly 0.13s of server time per 100 newsletters) while each
 * extra round trip costs the full WordPress bootstrap, so the request
 * count, not the query, is what a publisher waits on. Raising the
 * ceiling lets the client walk the same collection in far fewer trips.
 *
 * The walk still chunks rather than asking for everything at once, so a
 * site with thousands of newsletters can't turn one request into a
 * multi-megabyte response.
 *
 * This is not the per-page value the controls offer — that stays at 100
 * (`Admin_Shell_Preferences::PER_PAGE_MAX`).
 */
class Admin_Shell_Collection_Params {
	/**
	 * The `per_page` ceiling these collections accept. Mirrored client
	 * side by `FETCH_ALL_CHUNK_SIZE` in `utils/per-page.js`; change both
	 * together.
	 */
	const MAX_PER_PAGE = 1000;

	/**
	 * Boot hooks.
	 *
	 * Registered on `rest_api_init` rather than at load time because the
	 * CPTs and taxonomies these filters name are registered on `init`.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_filters' ] );
	}

	/**
	 * Hook the cap onto every collection the list screens read.
	 */
	public static function register_filters(): void {
		foreach ( self::get_collections() as $rest_base ) {
			add_filter( 'rest_' . $rest_base . '_collection_params', [ __CLASS__, 'raise_per_page_cap' ] );
		}
	}

	/**
	 * The post types and taxonomies whose collections back a list screen.
	 *
	 * @return array<string>
	 */
	public static function get_collections(): array {
		return [
			Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
			Ads::CPT,
			Ads::ADVERTISER_TAX,
			Newspack_Newsletters_Layouts::NEWSPACK_NEWSLETTERS_LAYOUT_CPT,
		];
	}

	/**
	 * Raise the collection's `per_page` maximum.
	 *
	 * @param array $params Collection parameters.
	 * @return array
	 */
	public static function raise_per_page_cap( $params ) {
		if ( isset( $params['per_page'] ) && is_array( $params['per_page'] ) ) {
			$params['per_page']['maximum'] = self::MAX_PER_PAGE;
		}
		return $params;
	}
}
