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
 * Per-user view preferences for the admin-shell list screens, stored in
 * user meta so they follow the user across browsers. Values are
 * bootstrapped into the localized `newspackNewslettersAdmin` global (see
 * `Admin_Shell_Assets`), so the REST route is write-only in practice.
 * Each screen gets its own user-meta key so a save only ever touches
 * that screen's value — two tabs saving different screens can't clobber
 * each other via a shared read-modify-write.
 *
 * The stored shape is the presentation half of the DataViews `view`:
 * layout type, sort, visible fields (in their display order), and the
 * per-layout settings (density, column widths, grid preview size), plus
 * items per page. Query state — page, search and filters — is
 * deliberately not stored, matching classic Screen Options: a saved
 * list configuration, not a saved query.
 *
 * A save replaces a screen's stored value outright; the client always
 * sends its complete presentation state. Field IDs are validated for
 * shape and size here, not against a per-screen allowlist — the screen
 * that reads them back drops the ones it no longer offers, which keeps
 * this class from having to track every screen's field set.
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

	const LAYOUT_TYPES = [ 'table', 'grid', 'list' ];
	const DENSITIES    = [ 'compact', 'balanced', 'comfortable' ];
	const ALIGNMENTS   = [ 'start', 'center', 'end' ];

	/**
	 * Caps on the free-form halves of the payload — field IDs and the
	 * per-column style map are keyed by whatever the screen defines, so
	 * bound them rather than trusting the client not to bloat user meta.
	 */
	const MAX_FIELDS         = 50;
	const MAX_STYLED_COLUMNS = 50;
	const MAX_ID_LENGTH      = 64;
	const MAX_WIDTH_LENGTH   = 16;

	/**
	 * Upper bound for the grid layout's preview-size control, which
	 * stores a pixel width (the slider positions map onto it). DataViews
	 * publishes no range, so this is a sanity check rather than a mirror.
	 */
	const MAX_PREVIEW_SIZE = 4000;

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
							'properties'           => self::get_prefs_schema(),
						],
					],
				],
			]
		);
	}

	/**
	 * Schema for the storable half of a DataViews view.
	 *
	 * `perPage` carries no range here — the "All" sentinel sits outside
	 * any single min/max pair, so the range check lives in
	 * `is_valid_per_page`.
	 *
	 * @return array
	 */
	private static function get_prefs_schema(): array {
		$column_style = [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'width'    => [ 'type' => [ 'string', 'integer' ] ],
				'minWidth' => [ 'type' => [ 'string', 'integer' ] ],
				'maxWidth' => [ 'type' => [ 'string', 'integer' ] ],
				'align'    => [
					'type' => 'string',
					'enum' => self::ALIGNMENTS,
				],
			],
		];

		return [
			'perPage'    => [ 'type' => 'integer' ],
			'type'       => [
				'type' => 'string',
				'enum' => self::LAYOUT_TYPES,
			],
			'titleField' => [ 'type' => 'string' ],
			'fields'     => [
				'type'     => 'array',
				'items'    => [ 'type' => 'string' ],
				'maxItems' => self::MAX_FIELDS,
			],
			'sort'       => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [
					'field'     => [ 'type' => 'string' ],
					'direction' => [
						'type' => 'string',
						'enum' => [ 'asc', 'desc' ],
					],
				],
			],
			'layout'     => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [
					'density'     => [
						'type' => 'string',
						'enum' => self::DENSITIES,
					],
					'previewSize' => [
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => self::MAX_PREVIEW_SIZE,
					],
					'styles'      => [
						'type'                 => 'object',
						'additionalProperties' => $column_style,
					],
				],
			],
		];
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
		$prefs = $request->get_param( 'prefs' );
		if ( ! is_array( $prefs ) ) {
			return self::invalid_preference_error();
		}

		// An explicit per-page outside the storable range is a client
		// bug, not a stale key — reject rather than silently dropping it.
		if ( isset( $prefs['perPage'] ) && ! self::is_valid_per_page( (int) $prefs['perPage'] ) ) {
			return self::invalid_preference_error();
		}

		$sanitized = self::sanitize_prefs( $prefs );
		if ( empty( $sanitized ) ) {
			return self::invalid_preference_error();
		}

		$screen_key = $request->get_param( 'screen' );
		update_user_meta( get_current_user_id(), self::get_user_meta_key( $screen_key ), $sanitized );

		return rest_ensure_response( self::get_preferences() );
	}

	/**
	 * The 400 returned for a payload with nothing storable in it.
	 *
	 * @return WP_Error
	 */
	private static function invalid_preference_error(): WP_Error {
		return new WP_Error(
			'newspack_newsletters_invalid_preference',
			__( 'Invalid preference value.', 'newspack-newsletters' ),
			[ 'status' => 400 ]
		);
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
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$sanitized = self::sanitize_prefs( $raw );
			if ( ! empty( $sanitized ) ) {
				$prefs[ $screen_key ] = $sanitized;
			}
		}
		return $prefs;
	}

	/**
	 * Normalize a preferences payload, dropping anything unstorable.
	 *
	 * Runs on write and again on read, so meta stored by an older
	 * plugin version — or hand-edited — can't reach the client
	 * malformed.
	 *
	 * @param array $prefs Raw preferences.
	 * @return array Sanitized preferences.
	 */
	public static function sanitize_prefs( array $prefs ): array {
		$clean = [];

		if ( isset( $prefs['perPage'] ) && is_numeric( $prefs['perPage'] ) && self::is_valid_per_page( (int) $prefs['perPage'] ) ) {
			$clean['perPage'] = (int) $prefs['perPage'];
		}

		if ( isset( $prefs['type'] ) && in_array( $prefs['type'], self::LAYOUT_TYPES, true ) ) {
			$clean['type'] = (string) $prefs['type'];
		}

		$title_field = isset( $prefs['titleField'] ) ? self::sanitize_field_id( $prefs['titleField'] ) : null;
		if ( null !== $title_field ) {
			$clean['titleField'] = $title_field;
		}

		if ( isset( $prefs['fields'] ) && is_array( $prefs['fields'] ) ) {
			$fields = [];
			foreach ( $prefs['fields'] as $field ) {
				$id = self::sanitize_field_id( $field );
				if ( null !== $id && ! in_array( $id, $fields, true ) ) {
					$fields[] = $id;
				}
				if ( count( $fields ) >= self::MAX_FIELDS ) {
					break;
				}
			}
			// An empty array is meaningful — every column hidden.
			$clean['fields'] = $fields;
		}

		$sort = isset( $prefs['sort'] ) && is_array( $prefs['sort'] ) ? self::sanitize_sort( $prefs['sort'] ) : null;
		if ( null !== $sort ) {
			$clean['sort'] = $sort;
		}

		$layout = isset( $prefs['layout'] ) && is_array( $prefs['layout'] ) ? self::sanitize_layout( $prefs['layout'] ) : null;
		if ( null !== $layout ) {
			$clean['layout'] = $layout;
		}

		return $clean;
	}

	/**
	 * A field ID reduced to a storable string, or null when unusable.
	 *
	 * @param mixed $value Candidate ID.
	 * @return string|null
	 */
	private static function sanitize_field_id( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$id = trim( $value );
		if ( '' === $id || strlen( $id ) > self::MAX_ID_LENGTH ) {
			return null;
		}
		return $id;
	}

	/**
	 * Sort clause, or null when neither half survives.
	 *
	 * @param array $sort Raw sort clause.
	 * @return array|null
	 */
	private static function sanitize_sort( array $sort ): ?array {
		$clean = [];

		$field = isset( $sort['field'] ) ? self::sanitize_field_id( $sort['field'] ) : null;
		if ( null !== $field ) {
			$clean['field'] = $field;
		}

		if ( isset( $sort['direction'] ) && in_array( $sort['direction'], [ 'asc', 'desc' ], true ) ) {
			$clean['direction'] = (string) $sort['direction'];
		}

		return empty( $clean ) ? null : $clean;
	}

	/**
	 * Layout settings for whichever layout the screen is in, or null
	 * when nothing storable remains.
	 *
	 * @param array $layout Raw layout settings.
	 * @return array|null
	 */
	private static function sanitize_layout( array $layout ): ?array {
		$clean = [];

		if ( isset( $layout['density'] ) && in_array( $layout['density'], self::DENSITIES, true ) ) {
			$clean['density'] = (string) $layout['density'];
		}

		if ( isset( $layout['previewSize'] ) && is_numeric( $layout['previewSize'] ) ) {
			$preview_size = (int) $layout['previewSize'];
			if ( $preview_size >= 0 && $preview_size <= self::MAX_PREVIEW_SIZE ) {
				$clean['previewSize'] = $preview_size;
			}
		}

		if ( isset( $layout['styles'] ) && is_array( $layout['styles'] ) ) {
			$styles = [];
			foreach ( $layout['styles'] as $field => $style ) {
				$id = self::sanitize_field_id( $field );
				if ( null === $id || ! is_array( $style ) ) {
					continue;
				}
				$column = self::sanitize_column_style( $style );
				if ( null !== $column ) {
					$styles[ $id ] = $column;
				}
				if ( count( $styles ) >= self::MAX_STYLED_COLUMNS ) {
					break;
				}
			}
			if ( ! empty( $styles ) ) {
				$clean['styles'] = $styles;
			}
		}

		return empty( $clean ) ? null : $clean;
	}

	/**
	 * One column's style entry, or null when nothing storable remains.
	 *
	 * @param array $style Raw column style.
	 * @return array|null
	 */
	private static function sanitize_column_style( array $style ): ?array {
		$clean = [];

		foreach ( [ 'width', 'minWidth', 'maxWidth' ] as $key ) {
			if ( ! isset( $style[ $key ] ) ) {
				continue;
			}
			$value = $style[ $key ];
			if ( is_int( $value ) || is_float( $value ) ) {
				$clean[ $key ] = (int) $value;
			} elseif ( is_string( $value ) && '' !== trim( $value ) && strlen( $value ) <= self::MAX_WIDTH_LENGTH ) {
				$clean[ $key ] = trim( $value );
			}
		}

		if ( isset( $style['align'] ) && in_array( $style['align'], self::ALIGNMENTS, true ) ) {
			$clean['align'] = (string) $style['align'];
		}

		return empty( $clean ) ? null : $clean;
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
