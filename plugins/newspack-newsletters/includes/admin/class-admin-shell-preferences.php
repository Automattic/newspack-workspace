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
 * practice. Each screen gets its own user-meta key so a save only ever
 * touches that screen's value — two tabs saving different screens can't
 * clobber each other via a shared read-modify-write.
 */
class Admin_Shell_Preferences {
	const API_NAMESPACE = 'newspack-newsletters/v1';
	const ROUTE         = 'admin-shell/preferences';
	const USER_META_KEY_PREFIX = 'newspack_newsletters_admin_view_prefs_';

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
							'type'                 => 'object',
							'required'             => true,
							'additionalProperties' => false,
							'properties'           => [
								'perPage' => [
									'type'     => 'integer',
									'required' => true,
								],
							],
						],
					],
				],
			]
		);
	}

	/**
	 * Capability gate — the floor for reaching any list screen. Some
	 * screens require more (layouts needs `edit_others_posts`), but a
	 * preference for a screen the user can't open is never read back, so
	 * the shared floor is enough.
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

		$screen_key = $request->get_param( 'screen' );
		update_user_meta( get_current_user_id(), self::get_user_meta_key( $screen_key ), [ 'perPage' => $per_page ] );

		return rest_ensure_response( self::get_preferences() );
	}

	/**
	 * The current user's sanitized preferences, keyed by screen.
	 *
	 * @return array
	 */
	public static function get_preferences(): array {
		$prefs = [];
		foreach ( self::SCREEN_KEYS as $screen_key ) {
			$raw = get_user_meta( get_current_user_id(), self::get_user_meta_key( $screen_key ), true );
			if ( ! is_array( $raw ) || ! isset( $raw['perPage'] ) || ! is_numeric( $raw['perPage'] ) ) {
				continue;
			}
			$per_page = (int) $raw['perPage'];
			if ( self::is_valid_per_page( $per_page ) ) {
				$prefs[ $screen_key ] = [ 'perPage' => $per_page ];
			}
		}
		return $prefs;
	}

	/**
	 * The user-meta key a screen's preferences are stored under.
	 *
	 * @param string $screen_key Screen identifier.
	 * @return string
	 */
	public static function get_user_meta_key( string $screen_key ): string {
		return self::USER_META_KEY_PREFIX . $screen_key;
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
