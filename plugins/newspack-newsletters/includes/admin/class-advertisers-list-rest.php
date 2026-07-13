<?php
/**
 * REST surface for the Newsletter Advertisers list DataView.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Admin;

defined( 'ABSPATH' ) || exit;

use Newspack_Newsletters\Ads;
use WP_Error;
use WP_REST_Request;

/**
 * Validation rails for the Advertiser taxonomy REST endpoints.
 *
 * The taxonomy's `show_in_rest` collection handles CRUD by default;
 * this class adds the rules the React DataView needs on top — currently
 * blocking self-referential `parent` on term updates.
 */
class Advertisers_List_REST {
	/**
	 * Boot hooks.
	 *
	 * `rest_pre_insert_<taxonomy>` is unusable here — the terms
	 * controller's `update_item` doesn't `is_wp_error()` the prepared
	 * value, so the WP_Error is cast to array and silently dropped.
	 * `rest_request_before_callbacks` fires after routing and treats
	 * a returned WP_Error as the response.
	 */
	public static function init(): void {
		add_filter( 'rest_request_before_callbacks', [ __CLASS__, 'guard_parent_self' ], 10, 3 );
	}

	/**
	 * Block term updates whose `parent` equals the term's own id.
	 *
	 * @param mixed           $response Earlier filter result; passed through if non-null.
	 * @param array           $handler  Route handler details.
	 * @param WP_REST_Request $request  Incoming REST request.
	 * @return mixed
	 */
	public static function guard_parent_self( $response, $handler, $request ) {
		unset( $handler );

		// Earlier callback already short-circuited — don't mask its signal.
		if ( null !== $response ) {
			return $response;
		}

		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		$route   = $request->get_route();
		$pattern = '#^/wp/v2/' . preg_quote( Ads::ADVERTISER_TAX, '#' ) . '/\d+$#';
		if ( ! preg_match( $pattern, $route ) ) {
			return $response;
		}

		$method = $request->get_method();
		if ( ! in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) ) {
			return $response;
		}

		$term_id = isset( $request['id'] ) ? (int) $request['id'] : 0;
		$parent  = $request->get_param( 'parent' );

		if ( null === $parent ) {
			return $response;
		}

		$parent = (int) $parent;
		if ( $term_id > 0 && $parent > 0 && $parent === $term_id ) {
			return new WP_Error(
				'newspack_newsletters_advertiser_parent_self',
				__( 'An advertiser cannot be its own parent.', 'newspack-newsletters' ),
				[ 'status' => 400 ]
			);
		}

		return $response;
	}
}
