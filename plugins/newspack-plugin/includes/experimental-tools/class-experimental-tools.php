<?php
/**
 * Experimental Tools.
 *
 * Filter-based registration system for experimental tools.
 * External plugins register tools via the `newspack_experimental_tools` filter.
 * This class provides option storage, REST API, and data accessors.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Experimental Tools manager.
 */
class Experimental_Tools {

	/**
	 * Option name for per-tool state.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'newspack_experimental_tools_settings';

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = NEWSPACK_API_NAMESPACE;

	/**
	 * REST route base.
	 *
	 * @var string
	 */
	const REST_ROUTE = '/experimental-tools';

	/**
	 * Usage data retention window in days. Used for pruning and as the
	 * upper bound for usage lookback queries.
	 *
	 * @var int
	 */
	const USAGE_RETENTION_DAYS = 90;

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	// ─── REST API ────────────────────────────────────────────────

	/**
	 * Register REST routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'api_get_tools' ],
				'permission_callback' => [ __CLASS__, 'check_permission' ],
			]
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE . '/(?P<slug>[a-z0-9-]+)/toggle',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'api_toggle_tool' ],
				'permission_callback' => [ __CLASS__, 'check_permission' ],
				'args'                => [
					'slug'    => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_title',
					],
					'enabled' => [
						'required'          => true,
						'sanitize_callback' => 'rest_sanitize_boolean',
					],
				],
			]
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE . '/(?P<slug>[a-z0-9-]+)/settings',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'api_save_settings' ],
				'permission_callback' => [ __CLASS__, 'check_permission' ],
				'args'                => [
					'slug'   => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_title',
					],
					'fields' => [
						'required'          => true,
						'type'              => 'object',
						'validate_callback' => function ( $value ) {
							return is_array( $value );
						},
					],
				],
			]
		);
	}

	/**
	 * Permission callback -- require manage_options.
	 *
	 * @return bool|\WP_Error
	 */
	public static function check_permission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'newspack_rest_forbidden',
				__( 'You cannot access this resource.', 'newspack-plugin' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}
		return true;
	}

	/**
	 * GET handler -- return all registered tools with state.
	 *
	 * @return \WP_REST_Response
	 */
	public static function api_get_tools() {
		return rest_ensure_response( self::get_tools() );
	}

	/**
	 * POST handler -- toggle a tool on/off.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function api_toggle_tool( $request ) {
		$slug    = $request['slug'];
		$enabled = $request['enabled'];

		$tools = self::get_registered_tools();
		if ( ! isset( $tools[ $slug ] ) ) {
			return new \WP_Error(
				'newspack_tool_not_found',
				__( 'Tool not found.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}

		// Reject toggle if a constant override is active.
		$tool = $tools[ $slug ];
		if ( ! empty( $tool['constant'] ) && defined( $tool['constant'] ) ) {
			return new \WP_Error(
				'newspack_tool_constant_override',
				/* translators: %s: constant name. */
				sprintf( __( 'This tool is controlled by the %s constant and cannot be toggled.', 'newspack-plugin' ), $tool['constant'] ),
				[ 'status' => 403 ]
			);
		}

		self::toggle_tool( $slug, $enabled );
		return rest_ensure_response( self::get_tools() );
	}

	/**
	 * POST handler -- save tool field values.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function api_save_settings( $request ) {
		$slug   = $request['slug'];
		$fields = $request['fields'];

		$tools = self::get_registered_tools();
		if ( ! isset( $tools[ $slug ] ) ) {
			return new \WP_Error(
				'newspack_tool_not_found',
				__( 'Tool not found.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}

		self::save_tool_fields( $slug, $fields );
		return rest_ensure_response( self::get_tools() );
	}

	// ─── Data accessors ──────────────────────────────────────────

	/**
	 * Get registered tools from the filter (raw, without saved state).
	 *
	 * @return array Keyed by slug.
	 */
	private static function get_registered_tools() {
		$raw = apply_filters( 'newspack_experimental_tools', [] );
		$tools = [];
		foreach ( $raw as $tool ) {
			if ( ! empty( $tool['slug'] ) ) {
				$slug           = sanitize_title( $tool['slug'] );
				$tool['slug']   = $slug;
				$tools[ $slug ] = $tool;
			}
		}
		return $tools;
	}

	/**
	 * Get all tools merged with saved state.
	 *
	 * @return array Flat array of tool objects for REST/JS consumption.
	 */
	public static function get_tools() {
		$registered = self::get_registered_tools();
		$all_settings = get_option( self::OPTION_NAME, [] );
		$tools = [];

		foreach ( $registered as $slug => $tool ) {
			$saved = isset( $all_settings[ $slug ] ) ? $all_settings[ $slug ] : [];

			// Determine enabled state.
			$constant_active = ! empty( $tool['constant'] ) && defined( $tool['constant'] );
			if ( $constant_active ) {
				$enabled = (bool) constant( $tool['constant'] );
			} else {
				$enabled = ! empty( $saved['enabled'] );
			}

			// Merge saved field values into declared fields.
			$fields = isset( $tool['fields'] ) ? $tool['fields'] : [];
			$saved_fields = isset( $saved['fields'] ) ? $saved['fields'] : [];
			foreach ( $fields as &$field ) {
				if ( isset( $saved_fields[ $field['key'] ] ) ) {
					$field['value'] = $saved_fields[ $field['key'] ];
				} elseif ( ! isset( $field['value'] ) && isset( $field['default'] ) ) {
					$field['value'] = $field['default'];
				}
			}
			unset( $field );

			$tools[] = [
				'slug'            => $slug,
				'label'           => $tool['label'] ?? $slug,
				'description'     => $tool['description'] ?? '',
				'disclosure'      => $tool['disclosure'] ?? '',
				'llm'             => $tool['llm'] ?? null,
				'constant'        => $tool['constant'] ?? null,
				'constant_active' => $constant_active,
				'enabled'         => $enabled,
				'enabled_at'      => $saved['enabled_at'] ?? null,
				'enabled_by'      => $saved['enabled_by'] ?? null,
				'fields'          => $fields,
				'usage_count'     => self::get_usage_count( $slug ),
			];
		}

		return $tools;
	}

	/**
	 * Get saved settings for a single tool.
	 *
	 * @param string $slug Tool slug.
	 * @return array
	 */
	public static function get_tool_settings( $slug ) {
		$all_settings = get_option( self::OPTION_NAME, [] );
		return isset( $all_settings[ $slug ] ) ? $all_settings[ $slug ] : [
			'enabled'    => false,
			'enabled_at' => null,
			'enabled_by' => null,
			'users'      => [],
			'fields'     => [],
		];
	}

	/**
	 * Check if a tool is enabled (option only -- does not check constants).
	 *
	 * @param string $slug Tool slug.
	 * @return bool
	 */
	public static function is_tool_enabled( $slug ) {
		$settings = self::get_tool_settings( $slug );
		return ! empty( $settings['enabled'] );
	}

	/**
	 * Toggle a tool on or off.
	 *
	 * @param string $slug    Tool slug.
	 * @param bool   $enabled Whether to enable.
	 */
	public static function toggle_tool( $slug, $enabled ) {
		$all_settings = get_option( self::OPTION_NAME, [] );
		if ( ! isset( $all_settings[ $slug ] ) ) {
			$all_settings[ $slug ] = [
				'enabled'    => false,
				'enabled_at' => null,
				'enabled_by' => null,
				'users'      => [],
				'fields'     => [],
			];
		}

		$all_settings[ $slug ]['enabled'] = (bool) $enabled;
		if ( $enabled ) {
			$all_settings[ $slug ]['enabled_at'] = time();
			$all_settings[ $slug ]['enabled_by'] = get_current_user_id();
		}

		update_option( self::OPTION_NAME, $all_settings );

		/**
		 * Fires when an experimental tool is toggled.
		 *
		 * @param string $slug    Tool slug.
		 * @param bool   $enabled Whether the tool was enabled or disabled.
		 */
		do_action( 'newspack_experimental_tool_toggled', $slug, $enabled );
	}

	/**
	 * Save field values for a tool.
	 *
	 * @param string $slug   Tool slug.
	 * @param array  $fields Key-value pairs of field values.
	 */
	public static function save_tool_fields( $slug, $fields ) {
		if ( ! is_array( $fields ) ) {
			return;
		}
		$registered = self::get_registered_tools();
		$all_settings = get_option( self::OPTION_NAME, [] );

		if ( ! isset( $all_settings[ $slug ] ) ) {
			$all_settings[ $slug ] = [
				'enabled'    => false,
				'enabled_at' => null,
				'enabled_by' => null,
				'users'      => [],
				'fields'     => [],
			];
		}

		// Only accept keys declared in the tool registration.
		$valid_keys = [];
		if ( isset( $registered[ $slug ]['fields'] ) ) {
			foreach ( $registered[ $slug ]['fields'] as $field ) {
				if ( ! empty( $field['key'] ) && ( $field['type'] ?? '' ) !== 'display' ) {
					$valid_keys[] = $field['key'];
				}
			}
		}

		foreach ( $fields as $key => $value ) {
			if ( in_array( $key, $valid_keys, true ) ) {
				$all_settings[ $slug ]['fields'][ $key ] = sanitize_textarea_field( $value );
			}
		}

		update_option( self::OPTION_NAME, $all_settings );

		/**
		 * Fires after a tool's field values are saved.
		 *
		 * @param string $slug   Tool slug.
		 * @param array  $fields Saved key-value pairs.
		 */
		do_action( 'newspack_experimental_tool_fields_saved', $slug, $all_settings[ $slug ]['fields'] );
	}

	/**
	 * Track usage for a tool by the current user.
	 *
	 * @param string $slug    Tool slug.
	 * @param int    $user_id User ID.
	 */
	public static function track_usage( $slug, $user_id ) {
		$all_settings = get_option( self::OPTION_NAME, [] );
		if ( ! isset( $all_settings[ $slug ] ) ) {
			$all_settings[ $slug ] = [
				'enabled'    => false,
				'enabled_at' => null,
				'enabled_by' => null,
				'users'      => [],
				'fields'     => [],
			];
		}

		$user_id = (string) $user_id;
		if ( ! isset( $all_settings[ $slug ]['users'] ) ) {
			$all_settings[ $slug ]['users'] = [];
		}
		if ( ! isset( $all_settings[ $slug ]['users'][ $user_id ] ) ) {
			$all_settings[ $slug ]['users'][ $user_id ] = [
				'daily' => [],
			];
		}

		$today = gmdate( 'Y-m-d' );
		if ( ! isset( $all_settings[ $slug ]['users'][ $user_id ]['daily'][ $today ] ) ) {
			$all_settings[ $slug ]['users'][ $user_id ]['daily'][ $today ] = 0;
		}
		$all_settings[ $slug ]['users'][ $user_id ]['daily'][ $today ]++;

		// Prune buckets older than the retention window to keep the option compact.
		$cutoff = gmdate( 'Y-m-d', time() - self::USAGE_RETENTION_DAYS * DAY_IN_SECONDS );
		foreach ( $all_settings[ $slug ]['users'][ $user_id ]['daily'] as $date => $count ) {
			if ( $date < $cutoff ) {
				unset( $all_settings[ $slug ]['users'][ $user_id ]['daily'][ $date ] );
			}
		}

		update_option( self::OPTION_NAME, $all_settings );
	}

	/**
	 * Get usage count for a tool within a number of recent days.
	 *
	 * @param string $slug Tool slug.
	 * @param int    $days Number of days to look back. Default 30.
	 * @return int
	 */
	public static function get_usage_count( $slug, $days = 30 ) {
		$settings = self::get_tool_settings( $slug );
		$total    = 0;
		$cutoff   = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );

		if ( ! empty( $settings['users'] ) ) {
			foreach ( $settings['users'] as $user_data ) {
				foreach ( $user_data['daily'] ?? [] as $date => $count ) {
					if ( $date >= $cutoff ) {
						$total += (int) $count;
					}
				}
			}
		}
		return $total;
	}

	/**
	 * Get usage count for a specific user within a number of recent days.
	 *
	 * @param string $slug    Tool slug.
	 * @param int    $user_id User ID.
	 * @param int    $days    Number of days to look back. Default 30.
	 * @return int
	 */
	public static function get_user_usage_count( $slug, $user_id, $days = 30 ) {
		$settings  = self::get_tool_settings( $slug );
		$user_key  = (string) $user_id;
		$user_data = $settings['users'][ $user_key ] ?? [];
		$total     = 0;
		$cutoff    = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );

		foreach ( $user_data['daily'] ?? [] as $date => $count ) {
			if ( $date >= $cutoff ) {
				$total += (int) $count;
			}
		}
		return $total;
	}
}

Experimental_Tools::init();
