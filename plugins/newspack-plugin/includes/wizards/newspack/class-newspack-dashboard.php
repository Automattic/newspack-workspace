<?php
/**
 * Newspack dashboard.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Common functionality for admin wizards. Override this class.
 */
class Newspack_Dashboard extends Wizard {

	/**
	 * Insights' own top-level page, registered by newspack-manager. Shadows
	 * `\Newspack_Manager\Insights\Loader::PAGE_SLUG`, which this plugin cannot
	 * reference: renaming it there silently falls this dashboard back to the
	 * external report.
	 *
	 * @var string
	 */
	const INSIGHTS_PAGE_SLUG = 'newspack-insights';

	/**
	 * The slug of this wizard.
	 *
	 * @var string
	 */
	protected $slug = 'newspack-dashboard';

	/**
	 * The capability required to access this.
	 *
	 * @var string
	 */
	protected $capability = 'manage_options';

	/**
	 * Use a high priorty so that the Newspack parent menu will be created
	 * prior to submenu items being added.
	 *
	 * @var int.
	 */
	protected $admin_menu_priority = 1;

	/**
	 * Get Dashboard data
	 *
	 * @return []
	 */
	public function get_dashboard() {
		$audience_cards = [
			[
				'icon'  => 'settings',
				'title' => __( 'Configuration', 'newspack-plugin' ),
				'desc'  => __( 'Set up reader registration, checkout, and emails.', 'newspack-plugin' ),
				'href'  => admin_url( 'admin.php?page=newspack-audience' ),
			],
			[
				'icon'  => 'megaphone',
				'title' => __( 'Campaigns', 'newspack-plugin' ),
				'desc'  => __( 'Target prompts to reader segments across your site.', 'newspack-plugin' ),
				'href'  => admin_url( 'admin.php?page=newspack-audience-campaigns' ),
			],
		];

		// Access Control only has a page while its feature flag is on.
		if ( Content_Gate::is_newspack_feature_enabled() ) {
			$audience_cards[] = [
				'icon'  => 'lock',
				'title' => __( 'Access Control', 'newspack-plugin' ),
				'desc'  => __( 'Restrict content to registered or paying readers.', 'newspack-plugin' ),
				'href'  => admin_url( 'admin.php?page=newspack-audience-access-control' ),
			];
		}

		// Donations is only available when the reader revenue platform is Newspack.
		if ( Donations::is_platform_wc() ) {
			$audience_cards[] = [
				'icon'  => 'gift',
				'title' => __( 'Donations', 'newspack-plugin' ),
				'desc'  => __( 'Accept one-time and recurring gifts from readers.', 'newspack-plugin' ),
				'href'  => admin_url( 'admin.php?page=newspack-audience-donations' ),
			];
		}

		$audience_cards[] = [
			'icon'  => 'plugins',
			'title' => __( 'Integrations', 'newspack-plugin' ),
			'desc'  => __( 'Connect your email provider and form tools to reader accounts.', 'newspack-plugin' ),
			'href'  => admin_url( 'admin.php?page=newspack-audience-integrations' ),
		];

		$audience_cards[] = [
			'icon'  => 'payment',
			'title' => __( 'Subscriptions', 'newspack-plugin' ),
			'desc'  => __( 'Set subscription options, discounts, and subscriber-only products.', 'newspack-plugin' ),
			'href'  => admin_url( 'admin.php?page=newspack-audience-subscriptions' ),
		];

		$dashboard = [
			'audience_development' => [
				'title' => __( 'Audience Management', 'newspack-plugin' ),
				'desc'  => __( 'Tools for registering readers and turning them into donors and subscribers.', 'newspack-plugin' ),
				'cards' => $audience_cards,
			],
		];

		// Newspack Newsletters Plugin.
		if ( defined( 'NEWSPACK_NEWSLETTERS_PLUGIN_FILE' ) ) { // phpcs:ignore phpcsSniffs.Constants.ConstantDocblock.Missing -- Presence check for another Newspack plugin, not a configurable constant.
			$dashboard['newsletters'] = [
				'title'        => __( 'Newsletters', 'newspack-plugin' ),
				'desc'         => __( 'Send newsletters and sell ads in them.', 'newspack-plugin' ),
				'dependencies' => [
					'newspack-newsletters',
				],
				'cards'        => [
					[
						'icon'  => 'envelope',
						'title' => __( 'All Newsletters', 'newspack-plugin' ),
						'desc'  => __( 'Browse sent newsletters and start a new one.', 'newspack-plugin' ),
						'href'  => admin_url( 'edit.php?post_type=newspack_nl_cpt' ),
					],
					[
						'icon'  => 'emailAd',
						'title' => __( 'Advertising', 'newspack-plugin' ),
						'desc'  => __( 'Sell ad placements in your newsletters.', 'newspack-plugin' ),
						'href'  => admin_url( 'edit.php?post_type=newspack_nl_ads_cpt' ),
					],
					[
						'icon'  => 'cog',
						'title' => __( 'Settings', 'newspack-plugin' ),
						'desc'  => __( 'Choose your email provider, subscription lists, and ad tracking.', 'newspack-plugin' ),
						'href'  => admin_url( 'edit.php?post_type=newspack_nl_cpt&page=newspack-newsletters' ),
					],
				],
			];
		}

		$dashboard['advertising'] = [
			'title'        => __( 'Advertising', 'newspack-plugin' ),
			'desc'         => __( 'Ad placements and sponsored content on your site.', 'newspack-plugin' ),
			'dependencies' => [
				'newspack-ads',
			],
			'cards'        => [
				[
					'icon'  => 'ad',
					'title' => __( 'Display Ads', 'newspack-plugin' ),
					'desc'  => __( 'Place ads on your site through Google Ad Manager and other ad providers.', 'newspack-plugin' ),
					'href'  => admin_url( 'admin.php?page=newspack-ads-display-ads#/' ),
				],
				[
					'icon'  => 'currencyDollar',
					'title' => __( 'Sponsors', 'newspack-plugin' ),
					'desc'  => __( 'Publish sponsored content and label it for readers.', 'newspack-plugin' ),
					'href'  => admin_url( 'edit.php?post_type=newspack_spnsrs_cpt' ),
				],
			],
		];

		// Newspack Listings Plugin.
		if ( defined( 'NEWSPACK_LISTINGS_FILE' ) ) { // phpcs:ignore phpcsSniffs.Constants.ConstantDocblock.Missing -- Documented in plugins/newspack-plugin/includes/wizards/class-listings-wizard.php.
			$dashboard['listings'] = [
				'title'        => __( 'Listings', 'newspack-plugin' ),
				'desc'         => __( 'Reusable entries, such as events and places, that you can place in any post.', 'newspack-plugin' ),
				'dependencies' => [
					'newspack-listings',
				],
				'cards'        => [
					[
						'icon'  => 'postDate',
						'title' => __( 'Events', 'newspack-plugin' ),
						'desc'  => __( 'Create an event once and reuse it in any post.', 'newspack-plugin' ),
						'href'  => admin_url( 'edit.php?post_type=newspack_lst_event' ),
					],
					[
						'icon'  => 'store',
						'title' => __( 'Marketplace Listings', 'newspack-plugin' ),
						'desc'  => __( 'Let readers post items and services for sale.', 'newspack-plugin' ),
						'href'  => admin_url( 'edit.php?post_type=newspack_lst_mktplce' ),
					],
					[
						'icon'  => 'postList',
						'title' => __( 'Generic Listing', 'newspack-plugin' ),
						'desc'  => __( 'Add structured entries on any topic and reuse them in posts.', 'newspack-plugin' ),
						'href'  => admin_url( 'edit.php?post_type=newspack_lst_generic' ),
					],
					[
						'icon'  => 'mapMarker',
						'title' => __( 'Places', 'newspack-plugin' ),
						'desc'  => __( 'Keep a directory of places in your coverage area.', 'newspack-plugin' ),
						'href'  => admin_url( 'edit.php?post_type=newspack_lst_place' ),
					],
					[
						'icon'  => 'cog',
						'title' => __( 'Settings', 'newspack-plugin' ),
						'desc'  => __( 'Set listing URLs, archives, and which details display.', 'newspack-plugin' ),
						'href'  => admin_url( 'admin.php?page=newspack-listings-settings-admin' ),
					],
				],
			];
		}

		// Newspack Network Plugin.
		if ( is_plugin_active( 'newspack-network/newspack-network.php' ) ) {
			$dashboard['network'] = [
				'title'        => __( 'Network', 'newspack-plugin' ),
				'desc'         => __( 'Connect this site with the other sites you publish.', 'newspack-plugin' ),
				'dependencies' => [
					'newspack-network',
				],
				'cards'        => $this->get_dashboard_network_cards(),
			];
		}

		return $dashboard;
	}

	/**
	 * Get Newspack Network plugin dashboard cards.
	 *
	 * @return array Cards
	 */
	public function get_dashboard_network_cards() {

		// Get the site role.
		$site_role = Network_Wizard::get_site_role();

		// Reusable card.
		$settings_card = [
			'icon'  => 'cog',
			'title' => __( 'Settings', 'newspack-plugin' ),
			'desc'  => __( 'Set this site’s role and its connection to the network.', 'newspack-plugin' ),
			'href'  => admin_url( 'admin.php?page=newspack-network' ),
		];

		// If hub.
		if ( 'hub' === $site_role ) {
			return [
				[
					'icon'  => 'globe',
					'title' => __( 'Nodes', 'newspack-plugin' ),
					'desc'  => __( 'See which sites belong to your network.', 'newspack-plugin' ),
					'href'  => admin_url( 'edit.php?post_type=newspack_hub_nodes' ),
				],
				[
					'icon'  => 'rotateRight',
					'title' => __( 'Subscriptions', 'newspack-plugin' ),
					'desc'  => __( 'See every subscription across your network.', 'newspack-plugin' ),
					'href'  => admin_url( 'edit.php?post_type=np_hub_subscriptions' ),
				],
				[
					'icon'  => 'currencyDollar',
					'title' => __( 'Orders', 'newspack-plugin' ),
					'desc'  => __( 'See every payment across your network.', 'newspack-plugin' ),
					'href'  => admin_url( 'edit.php?post_type=np_hub_orders' ),
				],
				[
					'icon'  => 'formatListBullets',
					'title' => __( 'Event Log', 'newspack-plugin' ),
					'desc'  => __( 'Review network events to trace sync problems.', 'newspack-plugin' ),
					'href'  => admin_url( 'admin.php?page=newspack-network-event-log' ),
				],
				$settings_card,
			];
		}

		// Node or no role.
		return [
			$settings_card,
		];
	}

	/**
	 * Get Dashboard local data
	 *
	 * @return []
	 */
	public function get_local_data() {
		$local_data = [
			'sections'     => $this->get_dashboard(),
			'plugins'      => get_plugins(),
			'siteStatuses' => [
				'readerActivation' => [
					'label'        => __( 'Audience Management', 'newspack-plugin' ),
					'statuses'     => [
						'success' => __( 'Enabled', 'newspack-plugin' ),
						'error'   => __( 'Disabled', 'newspack-plugin' ),
					],
					'endpoint'     => '/newspack/v1/wizard/newspack-audience/audience-management',
					'configLink'   => admin_url( 'admin.php?page=newspack-audience#/' ),
					'dependencies' => [
						'woocommerce' => [
							'label'    => __( 'WooCommerce', 'newspack-plugin' ),
							'isActive' => is_plugin_active( 'woocommerce/woocommerce.php' ),
						],
					],
				],
				'googleAdManager'  => [
					'label'            => __( 'Google Ad Manager', 'newspack-plugin' ),
					'statuses'         => [
						'error-preflight' => __( 'Disconnected', 'newspack-plugin' ),
					],
					'endpoint'         => '/newspack/v1/wizard/billboard',
					'isPreflightValid' => ( new Newspack_Ads_Configuration_Manager() )->is_gam_connected(),
					'configLink'       => admin_url( 'admin.php?page=newspack-ads-display-ads' ),
					'dependencies'     => [
						'newspack-ads' => [
							'label'    => __( 'Newspack Ads', 'newspack-plugin' ),
							'isActive' => is_plugin_active( 'newspack-ads/newspack-ads.php' ),
						],
					],
				],
				'googleAnalytics'  => [
					'label'        => __( 'Google Analytics', 'newspack-plugin' ),
					'endpoint'     => '/google-site-kit/v1/modules/analytics-4/data/settings',
					'configLink'   => in_array( 'analytics', get_option( 'googlesitekit_active_modules', [] ) ) ? admin_url( 'admin.php?page=googlesitekit-settings#/connected-services/analytics-4' ) : admin_url( 'admin.php?page=googlesitekit-splash' ),
					'dependencies' => [
						'google-site-kit' => [
							'label'    => __( 'Google Site Kit', 'newspack-plugin' ),
							'isActive' => is_plugin_active( 'google-site-kit/google-site-kit.php' ),
						],
					],
				],
			],
			'quickActions' => [],
		];

		if ( $this->can_open_editor_for( 'post' ) ) {
			$local_data['quickActions'][] = [
				'href'  => admin_url( 'post-new.php' ),
				'title' => __( 'Start a New Post', 'newspack-plugin' ),
				'icon'  => 'post',
			];
		}

		if ( $this->can_open_editor_for( 'newspack_nl_cpt' ) ) {
			$local_data['quickActions'][] = [
				'href'  => admin_url( 'post-new.php?post_type=newspack_nl_cpt' ),
				'title' => __( 'Draft a Newsletter', 'newspack-plugin' ),
				'icon'  => 'envelope',
			];
		} elseif ( $this->can_open_editor_for( 'page' ) ) {
			$local_data['quickActions'][] = [
				'href'  => admin_url( 'post-new.php?post_type=page' ),
				'title' => __( 'Create a Page', 'newspack-plugin' ),
				'icon'  => 'page',
			];
		}

		// Insights registers its own top-level page, so its absence here also covers
		// the feature flag and the setup-complete gate.
		$insights_url = menu_page_url( self::INSIGHTS_PAGE_SLUG, false );
		if ( $insights_url ) {
			$local_data['quickActions'][] = [
				// `menu_page_url()` escapes whether or not it echoes, and every sibling
				// href here is raw, so undo it rather than ship one entity-encoded URL.
				'href'  => wp_specialchars_decode( $insights_url ),
				'title' => __( 'Explore Insights', 'newspack-plugin' ),
				'icon'  => 'chartReport',
			];
		} else {
			$local_data['quickActions'][] = [
				'href'  => 'https://lookerstudio.google.com/u/0/reporting/b7026fea-8c2c-4c4b-be95-f582ed94f097/page/p_3eqlhk5odd',
				'title' => __( 'Open Data Dashboard', 'newspack-plugin' ),
				'icon'  => 'chartBar',
			];
		}

		return $local_data;
	}

	/**
	 * Whether a post type's editor is worth offering: registered with a UI, and
	 * openable by this user. The capabilities are the pair `post-new.php` checks,
	 * so a card that passes here cannot land on that screen's permission notice.
	 *
	 * @param string $post_type Post type name.
	 *
	 * @return bool
	 */
	private function can_open_editor_for( $post_type ) {
		$post_type_object = get_post_type_object( $post_type );

		return $post_type_object
			&& $post_type_object->show_ui
			&& current_user_can( $post_type_object->cap->edit_posts )
			&& current_user_can( $post_type_object->cap->create_posts );
	}

	/**
	 * Get the name for this wizard.
	 *
	 * @return string The wizard name.
	 */
	public function get_name() {
		return esc_html__( 'Newspack', 'newspack' );
	}

	/**
	 * Add a parent menu for Newspack and the first submenu item.
	 */
	public function add_page() {
		$icon = sprintf(
			'data:image/svg+xml;base64,%s',
			base64_encode( Newspack_UI_Icons::get_svg( 'newspack' ) )
		);

		add_menu_page(
			$this->get_name(),
			$this->get_name(),
			$this->capability,
			$this->slug,
			[ $this, 'render_wizard' ],
			$icon,
			3
		);
		$first_subnav_title = get_option( NEWSPACK_SETUP_COMPLETE ) ? __( 'Dashboard', 'newspack' ) : __( 'Setup', 'newspack' );
		add_submenu_page(
			$this->slug,
			$first_subnav_title,
			$first_subnav_title,
			$this->capability,
			$this->slug,
			[ $this, 'render_wizard' ]
		);
	}

	/**
	 * Load up JS/CSS.
	 */
	public function enqueue_scripts_and_styles() {
		parent::enqueue_scripts_and_styles();

		if ( filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) !== $this->slug ) {
			return;
		}

		/**
		 * JavaScript
		 */
		wp_localize_script(
			'newspack-wizards',
			'newspackDashboard',
			$this->get_local_data()
		);
		wp_enqueue_script( 'newspack-wizards' );
	}
}
