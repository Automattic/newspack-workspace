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
		$dashboard = [
			'audience_development' => [
				'title' => __( 'Audience Management', 'newspack-plugin' ),
				'desc'  => __( 'Engage your readers more deeply with tools to build customer relationships that drive towards sustainable revenue.', 'newspack-plugin' ),
				'cards' => [
					[
						'icon'  => 'settings',
						'title' => __( 'Configuration', 'newspack-plugin' ),
						'desc'  => __( 'Manage your Audience Management setup.', 'newspack-plugin' ),
						'href'  => admin_url( 'admin.php?page=newspack-audience' ),
					],
					[
						'icon'  => 'megaphone',
						'title' => __( 'Campaigns', 'newspack-plugin' ),
						'desc'  => __( 'Coordinate prompts across your site to drive metrics.', 'newspack-plugin' ),
						'href'  => admin_url( 'admin.php?page=newspack-audience-campaigns' ),
					],
					[
						'icon'  => 'gift',
						'title' => __( 'Donations', 'newspack-plugin' ),
						'desc'  => __( 'Bring in revenue through voluntary gifts.', 'newspack-plugin' ),
						'href'  => admin_url( 'admin.php?page=newspack-audience-donations' ),
					],
					[
						'icon'  => 'payment',
						'title' => __( 'Subscriptions', 'newspack-plugin' ),
						'desc'  => __( 'Gate your site\'s content behind a paywall.', 'newspack-plugin' ),
						'href'  => admin_url( 'admin.php?page=newspack-audience-subscriptions' ),
					],
				],
			],
		];

		// Newspack Newsletters Plugin.
		if ( defined( 'NEWSPACK_NEWSLETTERS_PLUGIN_FILE' ) ) {
			$dashboard['newsletters'] = [
				'title'        => __( 'Newsletters', 'newspack-plugin' ),
				'desc'         => __( 'Engage your readers directly in their email inbox.', 'newspack-plugin' ),
				'dependencies' => [
					'newspack-newsletters',
				],
				'cards'        => [
					[
						'icon'  => 'envelope',
						'title' => __( 'All Newsletters', 'newspack-plugin' ),
						'desc'  => __( 'See all newsletters you’ve sent out, and start new ones.', 'newspack-plugin' ),
						'href'  => admin_url( 'edit.php?post_type=newspack_nl_cpt' ),
					],
					[
						'icon'  => 'emailAd',
						'title' => __( 'Advertising', 'newspack-plugin' ),
						'desc'  => __( 'Get advertising revenue from your newsletters.', 'newspack-plugin' ),
						'href'  => admin_url( 'edit.php?post_type=newspack_nl_ads_cpt' ),
					],
					[
						'icon'  => 'tool',
						'title' => __( 'Settings', 'newspack-plugin' ),
						'desc'  => __( 'Configure tracking and other newsletter settings.', 'newspack-plugin' ),
						'href'  => admin_url( 'edit.php?post_type=newspack_nl_cpt&page=newspack-newsletters' ),
					],
				],
			];
		}

		$dashboard['advertising'] = [
			'title'        => __( 'Advertising', 'newspack-plugin' ),
			'desc'         => __( 'Sell space on your site to fund your operations.', 'newspack-plugin' ),
			'dependencies' => [
				'newspack-ads',
			],
			'cards'        => [
				[
					'icon'  => 'ad',
					'title' => __( 'Display Ads', 'newspack-plugin' ),
					'desc'  => __( 'Sell programmatic advertising on your site to drive revenue.', 'newspack-plugin' ),
					'href'  => admin_url( 'admin.php?page=newspack-ads-display-ads#/' ),
				],
				[
					'icon'  => 'currencyDollar',
					'title' => __( 'Sponsors', 'newspack-plugin' ),
					'desc'  => __( 'Sell sponsored content directly to purchasers.', 'newspack-plugin' ),
					'href'  => admin_url( 'edit.php?post_type=newspack_spnsrs_cpt' ),
				],
			],
		];

		// Newspack Listings Plugin.
		if ( defined( 'NEWSPACK_LISTINGS_FILE' ) ) {
			$dashboard['listings'] = [
				'title'        => __( 'Listings', 'newspack-plugin' ),
				'desc'         => __( 'Build databases of reusable or user-generated content to use on your site.', 'newspack-plugin' ),
				'dependencies' => [
					'newspack-listings',
				],
				'cards'        => [
					[
						'icon'  => 'postDate',
						'title' => __( 'Events', 'newspack-plugin' ),
						'desc'  => __( 'Easily use the same event information across multiple posts.', 'newspack-plugin' ),
						'href'  => admin_url( 'edit.php?post_type=newspack_lst_event' ),
					],
					[
						'icon'  => 'store',
						'title' => __( 'Marketplace Listings', 'newspack-plugin' ),
						'desc'  => __( 'Allow users to list items and services for sale.', 'newspack-plugin' ),
						'href'  => admin_url( 'edit.php?post_type=newspack_lst_mktplce' ),
					],
					[
						'icon'  => 'postList',
						'title' => __( 'Generic Listing', 'newspack-plugin' ),
						'desc'  => __( 'Manage any structured data for use in posts.', 'newspack-plugin' ),
						'href'  => admin_url( 'edit.php?post_type=newspack_lst_generic' ),
					],
					[
						'icon'  => 'mapMarker',
						'title' => __( 'Places', 'newspack-plugin' ),
						'desc'  => __( 'Create a database of places in your coverage area.', 'newspack-plugin' ),
						'href'  => admin_url( 'edit.php?post_type=newspack_lst_place' ),
					],
					[
						'icon'  => 'tool',
						'title' => __( 'Settings', 'newspack-plugin' ),
						'desc'  => __( 'Configure the way that Listings work on your site.', 'newspack-plugin' ),
						'href'  => admin_url( 'admin.php?page=newspack-listings-settings-admin' ),
					],
				],
			];
		}

		// Newspack Network Plugin.
		if ( is_plugin_active( 'newspack-network/newspack-network.php' ) ) {
			$dashboard['network'] = [
				'title'        => __( 'Network', 'newspack-plugin' ),
				'desc'         => __( 'Manage the way your site\'s content flows across your publishing network.', 'newspack-plugin' ),
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
			'icon'  => 'tool',
			'title' => __( 'Settings', 'newspack-plugin' ),
			'desc'  => __( 'Configure how Newspack Network functions.', 'newspack-plugin' ),
			'href'  => admin_url( 'admin.php?page=newspack-network' ),
		];

		// If hub.
		if ( 'hub' === $site_role ) {
			return [
				[
					'icon'  => 'globe',
					'title' => __( 'Nodes', 'newspack-plugin' ),
					'desc'  => __( 'Manage which sites are part of your content network.', 'newspack-plugin' ),
					'href'  => admin_url( 'edit.php?post_type=newspack_hub_nodes' ),
				],
				[
					'icon'  => 'rotateRight',
					'title' => __( 'Subscriptions', 'newspack-plugin' ),
					'desc'  => __( 'View all subscriptions across your network.', 'newspack-plugin' ),
					'href'  => admin_url( 'edit.php?post_type=np_hub_subscriptions' ),
				],
				[
					'icon'  => 'currencyDollar',
					'title' => __( 'Orders', 'newspack-plugin' ),
					'desc'  => __( 'View all payments across your network.', 'newspack-plugin' ),
					'href'  => admin_url( 'edit.php?post_type=np_hub_orders' ),
				],
				[
					'icon'  => 'formatListBullets',
					'title' => __( 'Event Log', 'newspack-plugin' ),
					'desc'  => __( 'Troubleshoot issues by viewing all events across your network.', 'newspack-plugin' ),
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
		$site_name = get_bloginfo( 'name' );
		$local_data = [
			'settings'     => [
				'siteName'      => $site_name,
				'headerBgColor' => get_theme_mod( 'primary_color_hex', '#f0f0f0' ),
			],
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
							'label'    => __( 'Woocommerce', 'newspack-plugin' ),
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

		$local_data['quickActions'][] = [
			'href'  => admin_url( 'post-new.php' ),
			'title' => __( 'Start a new post', 'newspack-plugin' ),
			'icon'  => 'post',
		];

		if ( defined( 'NEWSPACK_NEWSLETTERS_PLUGIN_FILE' ) ) {
			$local_data['quickActions'][] = [
				'href'  => admin_url( 'post-new.php?post_type=newspack_nl_cpt' ),
				'title' => __( 'Draft a newsletter', 'newspack-plugin' ),
				'icon'  => 'envelope',
			];
		}

		$local_data['quickActions'][] = [
			'href'  => 'https://lookerstudio.google.com/u/0/reporting/b7026fea-8c2c-4c4b-be95-f582ed94f097/page/p_3eqlhk5odd',
			'title' => __( 'Open data dashboard', 'newspack-plugin' ),
			'icon'  => 'chartBar',
		];

		return $local_data;
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
