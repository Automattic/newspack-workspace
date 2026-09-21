<?php
/**
 * Audience Integrations Wizard
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Integrations\Push_Log;
use WP_Error, WP_REST_Request, WP_REST_Response, WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Audience Integrations Wizard.
 */
class Audience_Integrations extends Wizard {
	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	protected $slug = 'newspack-audience-integrations';

	/**
	 * Parent slug.
	 *
	 * @var string
	 */
	protected $parent_slug = 'newspack-audience';

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( ! self::is_enabled() ) {
			return;
		}
		parent::__construct();
		add_action( 'rest_api_init', [ $this, 'register_api_endpoints' ] );
	}

	/**
	 * Check if the integrations settings feature is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return defined( 'NEWSPACK_INTEGRATIONS_SETTINGS_ENABLED' ) && NEWSPACK_INTEGRATIONS_SETTINGS_ENABLED;
	}

	/**
	 * Get the name for this wizard.
	 *
	 * @return string The wizard name.
	 */
	public function get_name() {
		return esc_html__( 'Audience Management / Integrations', 'newspack-plugin' );
	}

	/**
	 * Add Integrations page.
	 */
	public function add_page() {
		add_submenu_page(
			$this->parent_slug,
			$this->get_name(),
			esc_html__( 'Integrations', 'newspack-plugin' ),
			$this->capability,
			$this->slug,
			[ $this, 'render_wizard' ]
		);
	}

	/**
	 * Enqueue scripts and styles.
	 */
	public function enqueue_scripts_and_styles() {
		if ( ! $this->is_wizard_page() ) {
			return;
		}

		parent::enqueue_scripts_and_styles();

		wp_enqueue_script( 'newspack-wizards' );

		$localized_data = [
			'integrations_settings_enabled' => self::is_enabled(),
		];

		if ( class_exists( 'Newspack_Newsletters' ) ) {
			$localized_data['esp_provider'] = \Newspack_Newsletters::service_provider();
		}

		\wp_localize_script(
			'newspack-wizards',
			'newspackAudienceIntegrations',
			$localized_data
		);
	}

	/**
	 * Register the endpoints needed for the wizard screens.
	 */
	public function register_api_endpoints() {
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/settings',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_integration_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/settings/(?P<integration_id>[a-zA-Z0-9_-]+)',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_integration_settings' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/settings/(?P<integration_id>[a-zA-Z0-9_-]+)/enabled',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_integration_enabled' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'enabled' => [
						'type'     => 'boolean',
						'required' => true,
					],
				],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/settings/(?P<integration_id>[a-zA-Z0-9_-]+)/logs',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_integration_logs' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'per_page' => [
						'type'              => 'integer',
						'default'           => 25,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					],
					'page'     => [
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					],
					'orderby'  => [
						'type'    => 'string',
						'default' => 'scheduled_date_gmt',
						'enum'    => [ 'scheduled_date_gmt', 'action_id', 'hook', 'status' ],
					],
					'order'    => [
						'type'    => 'string',
						'default' => 'DESC',
						'enum'    => [ 'ASC', 'DESC' ],
					],
					'search'   => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'status'   => [
						'type'    => 'string',
						'default' => '',
						'enum'    => [ '', 'pending', 'in-progress', 'complete', 'failed', 'canceled' ],
					],
				],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/settings/(?P<integration_id>[a-zA-Z0-9_-]+)/logs/(?P<action_id>[0-9]+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_integration_log_detail' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'integration_id' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					],
					'action_id'      => [
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/settings/(?P<integration_id>[a-zA-Z0-9_-]+)/logs/(?P<action_id>[0-9]+)/run',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'api_run_integration_action' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'integration_id' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					],
					'action_id'      => [
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/settings/(?P<integration_id>[a-zA-Z0-9_-]+)/push-log',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_push_log' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'per_page'        => [
						'type'              => 'integer',
						'default'           => 25,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					],
					'page'            => [
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					],
					'order'           => [
						'type'    => 'string',
						'default' => 'DESC',
						'enum'    => [ 'ASC', 'DESC' ],
					],
					'search'          => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'status'          => [
						'type'    => 'string',
						'default' => '',
						'enum'    => [ '', Push_Log::STATUS_SUCCESS, Push_Log::STATUS_RETRYING, Push_Log::STATUS_FAILED ],
					],
					'operation'       => [
						'type'    => 'string',
						'default' => '',
						'enum'    => [ '', Push_Log::OPERATION_UPSERT, Push_Log::OPERATION_FLAG, Push_Log::OPERATION_DELETE ],
					],
					'needs_attention' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/settings/(?P<integration_id>[a-zA-Z0-9_-]+)/push-log/(?P<id>[0-9]+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_push_log_entry' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'integration_id' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					],
					'id'             => [
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	/**
	 * Get all integration settings.
	 *
	 * @return WP_REST_Response
	 */
	public function api_get_integration_settings() {
		return rest_ensure_response( Integrations::get_all_integration_settings() );
	}

	/**
	 * Update settings for a specific integration.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function api_update_integration_settings( WP_REST_Request $request ) {
		$integration_id = $request->get_param( 'integration_id' );
		$settings       = $request->get_param( 'settings' );

		if ( ! is_array( $settings ) ) {
			return new WP_Error(
				'newspack_invalid_param',
				esc_html__( 'Settings must be an object of key-value pairs.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}

		$result = Integrations::update_integration_settings( $integration_id, $settings );
		if ( null === $result ) {
			return new WP_Error(
				'newspack_integration_not_found',
				esc_html__( 'Integration not found.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}

		return rest_ensure_response( Integrations::get_all_integration_settings() );
	}

	/**
	 * Update the enabled state of a specific integration.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function api_update_integration_enabled( WP_REST_Request $request ) {
		$integration_id = $request->get_param( 'integration_id' );
		$enabled        = $request->get_param( 'enabled' );

		$integration = Integrations::get_integration( $integration_id );
		if ( ! $integration ) {
			return new WP_Error(
				'newspack_integration_not_found',
				esc_html__( 'Integration not found.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}

		if ( $enabled ) {
			if ( ! $integration->is_connected() ) {
				return new WP_Error(
					'newspack_integration_not_connected',
					esc_html__( 'This integration must be connected before it can be enabled.', 'newspack-plugin' ),
					[ 'status' => 400 ]
				);
			}
			$unsupported_reason = $integration->get_unsupported_reason();
			if ( $unsupported_reason ) {
				return new WP_Error(
					'newspack_integration_unsupported',
					esc_html( $unsupported_reason ),
					[ 'status' => 400 ]
				);
			}
			Integrations::enable( $integration_id );
		} else {
			Integrations::disable( $integration_id );
		}

		return rest_ensure_response( Integrations::get_all_integration_settings() );
	}

	/**
	 * Get activity logs for a specific integration.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function api_get_integration_logs( WP_REST_Request $request ) {
		$integration_id = $request->get_param( 'integration_id' );
		$integration    = Integrations::get_integration( $integration_id );

		if ( ! $integration ) {
			return new WP_Error(
				'newspack_integration_not_found',
				esc_html__( 'Integration not found.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}

		$per_page = max( 1, (int) $request->get_param( 'per_page' ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );

		$query_args = [
			'integration_id' => $integration_id,
			'per_page'       => $per_page,
			'offset'         => ( $page - 1 ) * $per_page,
			'orderby'        => $request->get_param( 'orderby' ),
			'order'          => $request->get_param( 'order' ),
		];

		$search = $request->get_param( 'search' );
		if ( ! empty( $search ) ) {
			$query_args['search'] = $search;
		}

		$status = $request->get_param( 'status' );
		if ( ! empty( $status ) ) {
			$query_args['status'] = $status;
		}

		$actions = Integrations::get_scheduled_actions( $query_args );

		$count_args = [
			'integration_id' => $integration_id,
		];
		if ( ! empty( $search ) ) {
			$count_args['search'] = $search;
		}
		if ( ! empty( $status ) ) {
			$count_args['status'] = $status;
		}
		$total = Integrations::count_scheduled_actions( $count_args );
		$hook_labels = Action_Scheduler::get_hook_labels();

		// Decode payloads once, then prime the user cache in a single query so
		// the per-row email resolution below doesn't issue a query per action.
		$decoded_args = [];
		$user_ids     = [];
		foreach ( $actions as $action ) {
			$args                               = self::decode_action_args( $action->args ?? '', $action->extended_args ?? '' );
			$decoded_args[ $action->action_id ] = $args;
			$user_id                            = self::get_payload_user_id( $args );
			if ( $user_id ) {
				$user_ids[] = $user_id;
			}
		}
		if ( ! empty( $user_ids ) ) {
			cache_users( array_values( array_unique( $user_ids ) ) );
		}

		$items = array_map(
			function ( $action ) use ( $hook_labels, $decoded_args ) {
				return [
					'id'        => $action->action_id,
					'timestamp' => $action->scheduled_date_gmt,
					'event'     => self::get_event_title( $hook_labels[ $action->hook ] ?? $action->hook, $decoded_args[ $action->action_id ] ?? null ),
					'status'    => $action->status,
					'email'     => self::extract_email_from_payload( $decoded_args[ $action->action_id ] ?? null ),
				];
			},
			$actions
		);

		return rest_ensure_response(
			[
				'items'    => array_values( $items ),
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
			]
		);
	}

	/**
	 * Get the full detail (payload + per-action logs) for a single scheduled action.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function api_get_integration_log_detail( WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$integration_id = $request->get_param( 'integration_id' );
		$action_id      = (int) $request->get_param( 'action_id' );

		$integration = Integrations::get_integration( $integration_id );
		if ( ! $integration ) {
			return new WP_Error(
				'newspack_integration_not_found',
				esc_html__( 'Integration not found.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}

		$action = Integrations::get_integration_action( $action_id, $integration_id );
		if ( ! $action ) {
			return new WP_Error(
				'newspack_action_not_found',
				esc_html__( 'Action not found.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}

		$store  = \ActionScheduler_Store::instance();
		$status = $store->get_status( $action_id );

		// The schedule's DateTime comes back in the server timezone despite the
		// column being named *_gmt. Normalize to UTC so the API contract matches
		// the field name and the frontend's '+00:00' parsing.
		$schedule     = $action->get_schedule();
		$scheduled_at = $schedule ? $schedule->get_date() : null;
		if ( $scheduled_at ) {
			$scheduled_at->setTimezone( new \DateTimeZone( 'UTC' ) );
		}
		$scheduled_at_gmt = $scheduled_at ? $scheduled_at->format( 'Y-m-d\TH:i:s' ) : '';

		// Resolve payload: prefer extended_args (full JSON) when present, else args.
		// We read from the AS DB directly because the ActionScheduler_Action object
		// only exposes the parsed args, with no signal as to whether they were
		// truncated. Reading the row tells us whether extended_args carries the
		// full JSON.
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT args, extended_args, attempts, last_attempt_gmt FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$action_id
			)
		);

		$payload_raw = '';
		if ( $row ) {
			$payload_raw = ! empty( $row->extended_args ) ? $row->extended_args : (string) $row->args;
		}
		$decoded = json_decode( $payload_raw, true );
		$args    = ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) ? $payload_raw : $decoded;

		$hook_labels = Action_Scheduler::get_hook_labels();
		$hook        = $action->get_hook();
		$event       = self::get_event_title( $hook_labels[ $hook ] ?? $hook, $args );

		return rest_ensure_response(
			[
				'action' => [
					'id'                 => $action_id,
					'hook'               => $hook,
					'event'              => $event,
					'email'              => self::extract_email_from_payload( $args ),
					'status'             => $status,
					'scheduled_date_gmt' => $scheduled_at_gmt,
					'attempts'           => $row ? (int) $row->attempts : 0,
					'last_attempt_gmt'   => $row && ! empty( $row->last_attempt_gmt ) && '0000-00-00 00:00:00' !== $row->last_attempt_gmt ? gmdate( 'Y-m-d\TH:i:s', strtotime( $row->last_attempt_gmt . ' UTC' ) ) : '',
					'group'              => $action->get_group(),
					'priority'           => (int) $action->get_priority(),
					'args'               => $args,
				],
				'logs'   => Action_Scheduler::get_action_logs( $action_id ),
			]
		);
	}

	/**
	 * Run a pending scheduled action immediately.
	 *
	 * Mirrors the WooCommerce Action Scheduler admin "Run" behavior: the action is
	 * processed synchronously and the post-run status is returned. Errors thrown by
	 * the action's callback are not surfaced as HTTP errors — AS already marks the
	 * action `failed` and writes a log entry, so we report `status: 'failed'` in a
	 * 200 response and let the UI surface the last log message.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function api_run_integration_action( WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$integration_id = $request->get_param( 'integration_id' );
		$action_id      = (int) $request->get_param( 'action_id' );

		$integration = Integrations::get_integration( $integration_id );
		if ( ! $integration ) {
			return new WP_Error(
				'newspack_integration_not_found',
				esc_html__( 'Integration not found.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}

		$action = Integrations::get_integration_action( $action_id, $integration_id );
		if ( ! $action ) {
			return new WP_Error(
				'newspack_action_not_found',
				esc_html__( 'Action not found.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}

		$store  = \ActionScheduler_Store::instance();
		$status = $store->get_status( $action_id );

		if ( \ActionScheduler_Store::STATUS_PENDING !== $status ) {
			return new WP_Error(
				'newspack_action_not_pending',
				esc_html__( 'This action is no longer pending.', 'newspack-plugin' ),
				[ 'status' => 409 ]
			);
		}

		// Read pre-run attempts so we can detect cases where process_action throws
		// before log_execution() — e.g. another worker claimed the action between
		// our pending check and process_action. AS doesn't always mark such cases
		// failed, so we surface a generic retry message instead of an empty
		// success-looking response.
		global $wpdb;
		$pre_attempts = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT attempts FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$action_id
			)
		);

		// Run synchronously like the WooCommerce AS admin "Run" button. No claim is taken,
		// so two concurrent requests for the same action could in theory both execute — same
		// limitation as the WC admin button, accepted at this scope.
		try {
			\ActionScheduler::runner()->process_action( $action_id, 'Newspack' );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Swallow: AS marks the action failed and writes a log entry inside process_action's
			// own error handler when the callback throws. We re-read state below and surface
			// it to the UI.
		}

		$new_status    = $store->get_status( $action_id );
		$post_attempts = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT attempts FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$action_id
			)
		);

		$ran = $new_status !== $status || $post_attempts > $pre_attempts;

		if ( $ran ) {
			$logs       = Action_Scheduler::get_action_logs( $action_id );
			$last_entry = end( $logs );
			$last_log   = ( is_array( $last_entry ) && isset( $last_entry['message'] ) ) ? $last_entry['message'] : '';
			$response   = [
				'status'  => $new_status,
				'message' => $last_log,
			];
			$audit_result = $new_status;
		} else {
			$response     = [
				'status'  => $new_status,
				'message' => esc_html__( 'Could not run; please refresh and try again.', 'newspack-plugin' ),
			];
			$audit_result = 'no-run';
		}

		Logger::newspack_log(
			'newspack_integration_action_run',
			sprintf( 'Manual run of integration action %d (%s).', $action_id, $integration_id ),
			[
				'action_id'      => $action_id,
				'integration_id' => $integration_id,
				'user_id'        => get_current_user_id(),
				'result'         => $audit_result,
			],
			'info'
		);

		return rest_ensure_response( $response );
	}

	/**
	 * Get the push log of an integration: what was sent for each reader.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function api_get_push_log( WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$integration_id = (string) $request->get_param( 'integration_id' );
		if ( ! Integrations::get_integration( $integration_id ) ) {
			return new WP_Error(
				'newspack_integration_not_found',
				esc_html__( 'Integration not found.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}

		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$result   = Push_Log::query(
			[
				'integration_id'  => $integration_id,
				'search'          => (string) $request->get_param( 'search' ),
				'status'          => (string) $request->get_param( 'status' ),
				'operation'       => (string) $request->get_param( 'operation' ),
				'needs_attention' => rest_sanitize_boolean( $request->get_param( 'needs_attention' ) ),
				'per_page'        => $per_page,
				'page'            => $page,
				'order'           => (string) $request->get_param( 'order' ),
			]
		);

		if ( is_wp_error( $result ) ) {
			return self::get_push_log_read_error( $result );
		}

		return rest_ensure_response(
			[
				'items'          => array_map( [ __CLASS__, 'format_push_log_item' ], $result['items'] ),
				'total'          => $result['total'],
				'page'           => $page,
				'per_page'       => $per_page,
				// The screen names the windows when it finds nothing, and a
				// site can filter them.
				'retention_days' => Push_Log::get_retention_days(),
			]
		);
	}

	/**
	 * Get one push log entry, with its fields compared with the reader's
	 * previous successful push.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function api_get_push_log_entry( WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$integration_id = (string) $request->get_param( 'integration_id' );
		$integration    = Integrations::get_integration( $integration_id );
		if ( ! $integration ) {
			return new WP_Error(
				'newspack_integration_not_found',
				esc_html__( 'Integration not found.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}

		$row = Push_Log::get( (int) $request->get_param( 'id' ), $integration_id );
		if ( is_wp_error( $row ) ) {
			return self::get_push_log_read_error( $row );
		}
		if ( ! $row ) {
			return new WP_Error(
				'newspack_push_log_entry_not_found',
				esc_html__( 'Entry not found.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}

		$predecessor = Push_Log::get_predecessor( $row );
		if ( is_wp_error( $predecessor ) ) {
			return self::get_push_log_read_error( $predecessor );
		}

		return rest_ensure_response(
			[
				'entry'       => self::format_push_log_item( $row ),
				'compared_to' => $predecessor ? [
					'id'         => $predecessor['id'],
					'updated_at' => $predecessor['updated_at'],
				] : null,
				'fields'      => Push_Log::compare_payloads( $row, $predecessor, (string) $integration->get_metadata_prefix() ),
			]
		);
	}

	/**
	 * Answer a push log read that failed as a server error.
	 *
	 * A table that did not answer is neither an empty log nor a missing
	 * entry, and the screen reports a failed load for a 500.
	 *
	 * @param WP_Error $error The error Push_Log returned.
	 * @return WP_Error
	 */
	private static function get_push_log_read_error( WP_Error $error ): WP_Error {
		return new WP_Error( $error->get_error_code(), $error->get_error_message(), [ 'status' => 500 ] );
	}

	/**
	 * Shape a push log row for the screen.
	 *
	 * The payload is left out: the screen reads it as the field comparison.
	 * The retry's action ID is replaced by its state, so the screen knows
	 * whether there is still a retry to run.
	 *
	 * @param array $row A push log row.
	 * @return array
	 */
	private static function format_push_log_item( array $row ): array {
		$row['retry'] = Push_Log::STATUS_RETRYING === $row['status']
			? self::get_retry_state( (int) $row['retry_action_id'], (string) $row['integration_id'] )
			: null;
		unset( $row['payload'], $row['retry_action_id'], $row['integration_id'] );

		return $row;
	}

	/**
	 * Whether a row's scheduled retry is still there to run, and when.
	 *
	 * A row can outlive its action: someone cancelled it, or Action Scheduler
	 * already ran or pruned it. Then there is nothing to run.
	 *
	 * @param int    $action_id      The Action Scheduler action.
	 * @param string $integration_id The integration the row belongs to.
	 * @return array{action_id:int,is_pending:bool,scheduled_at:?string}
	 */
	private static function get_retry_state( int $action_id, string $integration_id ): array {
		$state  = [
			'action_id'    => $action_id,
			'is_pending'   => false,
			'scheduled_at' => null,
		];
		$action = $action_id > 0 ? Integrations::get_integration_action( $action_id, $integration_id ) : null;
		if ( ! $action ) {
			return $state;
		}

		try {
			// The action can be pruned between the fetch and the status read;
			// one row must not take the page down.
			$status = \ActionScheduler_Store::instance()->get_status( $action_id );
		} catch ( \Throwable $e ) {
			return $state;
		}
		if ( \ActionScheduler_Store::STATUS_PENDING !== $status ) {
			return $state;
		}

		$state['is_pending'] = true;
		$schedule            = $action->get_schedule();
		$scheduled_at        = $schedule ? $schedule->get_date() : null;
		if ( $scheduled_at ) {
			// The date comes back in the server timezone; the screen reads GMT.
			$scheduled_at->setTimezone( new \DateTimeZone( 'UTC' ) );
			$state['scheduled_at'] = $scheduled_at->format( 'Y-m-d H:i:s' );
		}

		return $state;
	}

	/**
	 * Decode a scheduled action's args payload into a PHP value.
	 *
	 * Prefers extended_args (full JSON) over args, mirroring the detail
	 * endpoint. Returns null when the payload is empty or not valid JSON.
	 *
	 * @param string $args          The actionscheduler_actions.args column value.
	 * @param string $extended_args The actionscheduler_actions.extended_args column value.
	 *
	 * @return mixed The decoded payload, or null.
	 */
	private static function decode_action_args( $args, $extended_args ) {
		$raw = ! empty( $extended_args ) ? $extended_args : (string) $args;
		if ( '' === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			return null;
		}
		return $decoded;
	}

	/**
	 * Add the retry number to a scheduled action's title.
	 *
	 * The retries of one sync are separate actions with the same hook, so
	 * without the number they read as identical lines.
	 *
	 * @param string $label The hook's label.
	 * @param mixed  $args  The action's decoded args.
	 * @return string
	 */
	private static function get_event_title( $label, $args ) {
		$retry_data  = is_array( $args ) && isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : [];
		$retry_count = isset( $retry_data['retry_count'] ) ? (int) $retry_data['retry_count'] : 0;
		if ( $retry_count < 1 ) {
			return $label;
		}

		$max_retries = isset( $retry_data['max_retries'] ) ? (int) $retry_data['max_retries'] : 0;
		if ( $max_retries < $retry_count ) {
			/* translators: 1: scheduled action name, ending in "Retry". 2: which retry this is. */
			return sprintf( __( '%1$s %2$d', 'newspack-plugin' ), $label, $retry_count );
		}

		/* translators: 1: scheduled action name, ending in "Retry". 2: which retry this is. 3: how many retries a sync gets. */
		return sprintf( __( '%1$s %2$d of %3$d', 'newspack-plugin' ), $label, $retry_count, $max_retries );
	}

	/**
	 * Best-effort resolution of the contact email for a scheduled action's payload.
	 *
	 * Integration retry actions reference the contact by WordPress user ID
	 * (e.g. `[ { "integration_id": "esp", "user_id": 1, ... } ]`) rather than
	 * carrying the email directly, so the current account email is resolved
	 * from that ID. An explicit `email`/`user_email` key is preferred when a
	 * payload does carry one, and a `previous_email` (set on email-change
	 * retries) is used as a last resort when the user can no longer be
	 * resolved. Returns '' when no email can be determined.
	 *
	 * @param mixed $payload Decoded args payload (array, scalar, or null).
	 * @param int   $depth   Current recursion depth (internal guard).
	 *
	 * @return string The resolved email, or ''.
	 */
	private static function extract_email_from_payload( $payload, $depth = 0 ) {
		if ( $depth > 6 || ! is_array( $payload ) ) {
			return '';
		}
		// Prefer an explicit email carried in the payload.
		foreach ( [ 'email', 'user_email' ] as $key ) {
			if ( isset( $payload[ $key ] ) && is_string( $payload[ $key ] ) && is_email( $payload[ $key ] ) ) {
				return sanitize_email( $payload[ $key ] );
			}
		}
		// Otherwise resolve the current account email from the user ID.
		if ( isset( $payload['user_id'] ) && is_numeric( $payload['user_id'] ) ) {
			$user = get_userdata( (int) $payload['user_id'] );
			if ( $user && is_email( $user->user_email ) ) {
				return sanitize_email( $user->user_email );
			}
		}
		// Fall back to a previous email (email-change retries) when the user is gone.
		if ( isset( $payload['previous_email'] ) && is_string( $payload['previous_email'] ) && is_email( $payload['previous_email'] ) ) {
			return sanitize_email( $payload['previous_email'] );
		}
		foreach ( $payload as $value ) {
			if ( is_array( $value ) ) {
				$found = self::extract_email_from_payload( $value, $depth + 1 );
				if ( '' !== $found ) {
					return $found;
				}
			}
		}
		return '';
	}

	/**
	 * Find the first WordPress user ID referenced in a scheduled action's payload.
	 *
	 * Used to prime the user cache before bulk email resolution. Mirrors the
	 * structure walked by extract_email_from_payload().
	 *
	 * @param mixed $payload Decoded args payload (array, scalar, or null).
	 * @param int   $depth   Current recursion depth (internal guard).
	 *
	 * @return int The first user ID found, or 0.
	 */
	private static function get_payload_user_id( $payload, $depth = 0 ) {
		if ( $depth > 6 || ! is_array( $payload ) ) {
			return 0;
		}
		if ( isset( $payload['user_id'] ) && is_numeric( $payload['user_id'] ) ) {
			return (int) $payload['user_id'];
		}
		foreach ( $payload as $value ) {
			if ( is_array( $value ) ) {
				$user_id = self::get_payload_user_id( $value, $depth + 1 );
				if ( $user_id ) {
					return $user_id;
				}
			}
		}
		return 0;
	}
}
