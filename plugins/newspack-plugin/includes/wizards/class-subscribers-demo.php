<?php
/**
 * Newspack Subscribers Demo.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

require_once NEWSPACK_ABSPATH . '/includes/wizards/class-wizard.php';

/**
 * Subscribers demo wizard.
 */
class Subscribers_Demo extends Wizard {

	/**
	 * The slug of this wizard.
	 *
	 * @var string
	 */
	protected $slug = 'newspack-subscribers-demo';

	/**
	 * The capability required to access this wizard.
	 *
	 * @var string
	 */
	protected $capability = 'manage_options';

	/**
	 * Whether the wizard should be displayed in the Newspack submenu.
	 *
	 * @var bool
	 */
	protected $hidden = true;

	/**
	 * Constructor.
	 *
	 * @param array $args Optional arguments.
	 */
	public function __construct( $args = [] ) {
		parent::__construct( $args );
		add_action( 'rest_api_init', [ $this, 'register_api_endpoints' ] );
	}

	/**
	 * Get the name for this wizard.
	 *
	 * @return string The wizard name.
	 */
	public function get_name() {
		return esc_html__( 'Subscribers demo', 'newspack' );
	}

	/**
	 * Register REST endpoints.
	 */
	public function register_api_endpoints() {
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/avatars',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'api_get_avatars' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'emails' => [
						'type'     => 'array',
						'required' => true,
						'items'    => [ 'type' => 'string' ],
					],
					'size'   => [
						'type'              => 'integer',
						'default'           => 64,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	/**
	 * Resolve avatar URLs for a set of emails via core get_avatar_url(), which
	 * honors the Settings → Discussion avatar options and any avatar plugin.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function api_get_avatars( $request ) {
		if ( ! get_option( 'show_avatars', true ) ) {
			return rest_ensure_response( [ 'show' => false ] );
		}
		// Callers request 2x their render size so avatars stay crisp on high-DPR
		// displays (list: 32px → 64, profile: 64px → 128).
		$size    = $request->get_param( 'size' );
		$avatars = [];
		foreach ( $request->get_param( 'emails' ) as $email ) {
			$avatars[ $email ] = get_avatar_url( $email, [ 'size' => $size ] );
		}
		return rest_ensure_response(
			[
				'show'    => true,
				'avatars' => $avatars,
			]
		);
	}

	/**
	 * Enqueue Subscribers Demo scripts and styles.
	 */
	public function enqueue_scripts_and_styles() {
		parent::enqueue_scripts_and_styles();

		if ( filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) !== $this->slug ) {
			return;
		}

		$asset = include NEWSPACK_ABSPATH . 'dist/subscribersDemo.asset.php';

		wp_enqueue_script(
			'newspack-subscribers-demo',
			Newspack::plugin_url() . '/dist/subscribersDemo.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// Mirror the publisher's configurable group/team label so the prototype
		// stays consistent with the Audience → Setup "Group labels" override.
		$group_label_singular = class_exists( '\Newspack\Group_Subscription' )
			? \Newspack\Group_Subscription::get_label( 'singular' )
			: __( 'Group', 'newspack' );
		$group_label_plural = class_exists( '\Newspack\Group_Subscription' )
			? \Newspack\Group_Subscription::get_label( 'plural' )
			: __( 'Groups', 'newspack' );

		// Mirror the site's WooCommerce currency presentation so amounts render in
		// the publisher's currency rather than a hardcoded "$".
		$currency = [
			'symbol'   => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol() ) : '$',
			'position' => function_exists( 'get_option' ) ? (string) get_option( 'woocommerce_currency_pos', 'left' ) : 'left',
		];

		wp_add_inline_script(
			'newspack-subscribers-demo',
			'window.newspackSubscribersDemo = ' . wp_json_encode(
				[
					'groupLabel'       => $group_label_singular,
					'groupLabelPlural' => $group_label_plural,
					'currency'         => $currency,
					// Drives the column layout synchronously; the avatar URLs
					// themselves come from the /avatars REST endpoint.
					'showAvatars'      => (bool) get_option( 'show_avatars', true ),
					// Newsletters shown before the "View more" toggle. Defaults to
					// 4; override per-site with the NEWSPACK_SUBSCRIBERS_DEMO_NEWSLETTERS_LIMIT
					// wp-config constant if a publisher finds the list too long.
					'newslettersLimit' => defined( 'NEWSPACK_SUBSCRIBERS_DEMO_NEWSLETTERS_LIMIT' )
						? (int) NEWSPACK_SUBSCRIBERS_DEMO_NEWSLETTERS_LIMIT
						: 4,
					// Cancelled subscriptions shown before the "View more" toggle when a
					// reader has no active or on-hold plan. Defaults to 1; override with
					// the NEWSPACK_SUBSCRIBERS_DEMO_CANCELLED_LIMIT wp-config constant.
					'cancelledLimit'   => defined( 'NEWSPACK_SUBSCRIBERS_DEMO_CANCELLED_LIMIT' )
						? (int) NEWSPACK_SUBSCRIBERS_DEMO_CANCELLED_LIMIT
						: 1,
				]
			) . ';',
			'before'
		);

		wp_enqueue_style(
			'newspack-subscribers-demo',
			Newspack::plugin_url() . '/dist/subscribersDemo.css',
			[ 'wp-components' ],
			NEWSPACK_PLUGIN_VERSION
		);
	}
}
