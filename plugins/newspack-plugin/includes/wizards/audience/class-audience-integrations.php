<?php
/**
 * Audience Integrations Wizard
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Integrations;
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
						'enum'    => [ '', 'pending', 'complete', 'failed', 'canceled' ],
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

		$items = array_map(
			function ( $action ) use ( $hook_labels ) {
				return [
					'id'        => $action->action_id,
					'timestamp' => $action->scheduled_date_gmt,
					'event'     => $hook_labels[ $action->hook ] ?? $action->hook,
					'status'    => $action->status,
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
}
