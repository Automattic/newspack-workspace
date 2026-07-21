<?php
/**
 * Admin shell — per-user view preferences.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Admin;

use WP_Error;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Per-user view preferences for the admin-shell list screens (items per
 * page), stored in user meta so they follow the user across browsers.
 * Values are bootstrapped into the localized `newspackNewslettersAdmin`
 * global (see `Admin_Shell_Assets`), so the REST route is write-only in
 * practice.
 */
class Admin_Shell_Preferences {
	const API_NAMESPACE = 'newspack-newsletters/v1';
	const ROUTE         = 'admin-shell/preferences';
	const USER_META_KEY = 'newspack_newsletters_admin_view_prefs';

	const SCREEN_KEYS = [ 'newsletters-list', 'ads-list', 'advertisers-list', 'layouts-list' ];

	/**
	 * Client-side sentinel for "All" — the REST API caps `per_page` at
	 * 100, so the client fetches in chunks and concatenates.
	 */
	const PER_PAGE_ALL = -1;
	const PER_PAGE_MAX = 100;

	/**
	 * Boot hooks.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Register the update route.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/' . self::ROUTE,
			[
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ __CLASS__, 'update_preferences' ],
					'permission_callback' => [ __CLASS__, 'permission_check' ],
					'args'                => [
						'screen' => [
							'type'     => 'string',
							'enum'     => self::SCREEN_KEYS,
							'required' => true,
						],
						'prefs'  => [
							'type'     => 'object',
							'required' => true,
						],
					],
				],
			]
		);
	}

	/**
	 * Capability gate — matches the list screens themselves.
	 *
	 * @return bool
	 */
	public static function permission_check(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Persist a screen's preferences for the current user.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public static function update_preferences( $request ) {
		$prefs    = $request->get_param( 'prefs' );
		$per_page = is_array( $prefs ) && isset( $prefs['perPage'] ) && is_numeric( $prefs['perPage'] ) ? (int) $prefs['perPage'] : null;
		if ( null === $per_page || ! self::is_valid_per_page( $per_page ) ) {
			return new WP_Error(
				'newspack_newsletters_invalid_preference',
				__( 'Invalid preference value.', 'newspack-newsletters' ),
				[ 'status' => 400 ]
			);
		}

		$all_prefs = self::get_preferences();

		$all_prefs[ $request->get_param( 'screen' ) ] = [ 'perPage' => $per_page ];
		update_user_meta( get_current_user_id(), self::USER_META_KEY, $all_prefs );

		return rest_ensure_response( $all_prefs );
	}

	/**
	 * The current user's sanitized preferences, keyed by screen.
	 *
	 * @return array
	 */
	public static function get_preferences(): array {
		$raw = get_user_meta( get_current_user_id(), self::USER_META_KEY, true );
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$prefs = [];
		foreach ( self::SCREEN_KEYS as $screen_key ) {
			if ( ! isset( $raw[ $screen_key ]['perPage'] ) || ! is_numeric( $raw[ $screen_key ]['perPage'] ) ) {
				continue;
			}
			$per_page = (int) $raw[ $screen_key ]['perPage'];
			if ( self::is_valid_per_page( $per_page ) ) {
				$prefs[ $screen_key ] = [ 'perPage' => $per_page ];
			}
		}
		return $prefs;
	}

	/**
	 * Whether a per-page value is storable.
	 *
	 * @param int $value Candidate value.
	 * @return bool
	 */
	private static function is_valid_per_page( int $value ): bool {
		return self::PER_PAGE_ALL === $value || ( $value >= 1 && $value <= self::PER_PAGE_MAX );
	}
}
