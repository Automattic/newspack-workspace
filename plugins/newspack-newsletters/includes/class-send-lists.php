<?php
/**
 * Newspack Newsletters Send Lists.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters;

use Newspack_Newsletters;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Main Newspack Newsletters Send_Lists class.
 *
 * A Send_List is a collection of contacts which can be sent newsletter content.
 * A Send_List can be a top-level list or a sublist of a list.
 * Each Send_List corresponds to an entity in a supported ESP, but
 * shares the same data schema for interactions within this plugin.
 */
class Send_Lists {
	/**
	 * Initialize this class and register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		\add_action( 'rest_api_init', [ __CLASS__, 'register_api_endpoints' ] );
	}

	/**
	 * Check if we should initialize Send lists. Needs to be called after init.
	 *
	 * @return boolean
	 */
	public static function should_initialize_send_lists() {
		// If Service Provider is not configured yet.
		if ( 'manual' === Newspack_Newsletters::service_provider() || ! Newspack_Newsletters::is_service_provider_configured() ) {
			return false;
		}

		return true;
	}

	/**
	 * Register the endpoints needed to fetch send lists.
	 */
	public static function register_api_endpoints() {

		if ( ! self::should_initialize_send_lists() ) {
			return;
		}

		\register_rest_route(
			Newspack_Newsletters::API_NAMESPACE,
			'/send-lists',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'api_get_send_lists' ],
				'permission_callback' => [ 'Newspack_Newsletters', 'api_authoring_permissions_check' ],
				'args'                => [
					'ids'       => [
						'type' => [ 'array', 'string' ],
					],
					'search'    => [
						'type' => [ 'array', 'string' ],
					],
					'type'      => [
						'type' => 'string',
					],
					'parent_id' => [
						'type' => 'string',
					],
					'provider'  => [
						'type' => 'string',
					],
					'limit'     => [
						'type' => [ 'integer', 'string' ],
					],
				],
			]
		);
	}

	/**
	 * Get default arguments for the send lists API. Supported keys;
	 *
	 * - ids: ID or array of send IDs to fetch. If passed, will take precedence over `search`.
	 * - search: Search term or array of search terms to filter send lists. If `ids` is passed, will be ignored.
	 * - type: Type of send list to filter. Supported terms are 'list' or 'sublist', otherwise all types will be fetched.
	 * - parent_id: Parent ID to filter by when fetching sublists. If `type` is 'list`, will be ignored.
	 * - limit: Limit the number of send lists to return.
	 *
	 * @return array
	 */
	public static function get_default_args() {
		return [
			'ids'       => null,
			'search'    => null,
			'type'      => null,
			'parent_id' => null,
			'limit'     => null,
		];
	}

	/**
	 * Check if an ID or array of IDs to search matches the given ID.
	 *
	 * @param array|string $ids ID or array of IDs to search.
	 * @param string       $id ID to match against.
	 *
	 * @return boolean
	 */
	public static function matches_id( $ids, $id ) {
		if ( is_array( $ids ) ) {
			return in_array( $id, $ids, false ); // phpcs:ignore WordPress.PHP.StrictInArray.FoundNonStrictFalse
		}
		return (string) $id === (string) $ids;
	}

	/**
	 * Check if the given search term matches any of the given strings.
	 *
	 * @param null|array|string $search Search term or array of terms. If null, return true.
	 * @param array             $matches An array of strings to match against.
	 *
	 * @return boolean
	 */
	public static function matches_search( $search, $matches = [] ) {
		if ( null === $search ) {
			return true;
		}
		if ( ! is_array( $search ) ) {
			$search = [ $search ];
		}
		foreach ( $search as $to_match ) {
			// Don't try to match values that will convert to empty strings, or that we can't convert to a string.
			if ( ! $to_match || is_array( $to_match ) ) {
				continue;
			}
			$to_match = strtolower( strval( $to_match ) );
			foreach ( $matches as $match ) {
				if ( stripos( strtolower( strval( $match ) ), $to_match ) !== false ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * API handler to fetch send lists for the given provider.
	 * Send_List objects are converted to arrays of config data before being returned.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_REST_Response|WP_Error WP_REST_Response on success, or WP_Error object on failure.
	 */
	public static function api_get_send_lists( $request ) {
		$provider_slug = $request['provider'] ?? null;
		$provider      = $provider_slug ? Newspack_Newsletters::get_service_provider_instance( $provider_slug ) : Newspack_Newsletters::get_service_provider();
		if ( empty( $provider ) ) {
			return new WP_Error( 'newspack_newsletters_invalid_provider', __( 'Invalid provider, or provider not set.', 'newspack-newsletters' ) );
		}

		$defaults      = self::get_default_args();
		$args          = [];
		foreach ( $defaults as $key => $value ) {
			$args[ $key ] = $request[ $key ] ?? $value;
		}
		return \rest_ensure_response( $provider->get_send_lists( $args, true ) );
	}
}
