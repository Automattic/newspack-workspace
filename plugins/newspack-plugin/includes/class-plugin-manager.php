<?php
/**
 * Newspack setup
 *
 * @package Newspack
 */

namespace Newspack;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * General purpose class for managing installation/activation of plugins.
 */
class Plugin_Manager {

	/**
	 * Array of Newspack required plugins.
	 *
	 * @var array
	 */
	public static $required_plugins = [
		'akismet',
		'jetpack',
		'pwa',
		'google-site-kit',
		'newspack-blocks',
		'wp-parsely',
		'perfmatters',
	];

	/**
	 * Per-request memoization for get_managed_plugin_status(), keyed by slug.
	 *
	 * The underlying get_installed_plugins() calls wp_get_themes() which rescans
	 * /themes on every call (get_plugins() is cached intra-request, themes are
	 * not). With multiple integrations declaring the same required plugin, that
	 * scan ran once per lookup. The cache is per-request, so plugin state changes
	 * between requests are picked up on the next call.
	 *
	 * @var array<string, string>
	 */
	private static $managed_plugin_status_cache = [];

	/**
	 * Get info about all the managed plugins and their status.
	 *
	 * @todo Define what the structure of this looks like better and load it up from a config or something.
	 *
	 * @return array of plugins info.
	 */
	public static function get_managed_plugins() {
		$managed_plugins = [
			'akismet'                     => [
				'Name'        => \esc_html__( 'Akismet Anti-Spam', 'newspack-plugin' ),
				'Description' => \esc_html__( 'Used by millions, Akismet is quite possibly the best way in the world to protect your blog from spam. It keeps your site protected even while you sleep.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'Automattic', 'newspack-plugin' ),
				'AuthorURI'   => \esc_url( 'https://automattic.com/wordpress-plugins/' ),
				'PluginURI'   => \esc_url( 'https://akismet.com/' ),
				'Download'    => 'wporg',
			],
			'co-authors-plus'             => [
				'Name'        => \esc_html__( 'Co-Authors Plus', 'newspack-plugin' ),
				'Description' => \esc_html__( 'Allows multiple authors and guest authors to be assigned to a post.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'Mohammad Jangda, Daniel Bachhuber, Automattic, Weston Ruter', 'newspack-plugin' ),
				'AuthorURI'   => \esc_url( 'https://wordpress.org/extend/plugins/co-authors-plus/' ),
				'PluginURI'   => \esc_url( 'https://wordpress.org/extend/plugins/co-authors-plus/' ),
				'Download'    => 'wporg',
			],
			'google-site-kit'             => [
				'Name'        => \esc_html__( 'Google Site Kit', 'newspack-plugin' ),
				'Description' => \esc_html__( 'Site Kit is is a one-stop solution for WordPress users to use everything Google has to offer to make them successful on the web.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'Google', 'newspack-plugin' ),
				'AuthorURI'   => \esc_url( 'https://opensource.google.com' ),
				'PluginURI'   => \esc_url( 'https://sitekit.withgoogle.com/' ),
				'Download'    => 'wporg',
				'EditPath'    => 'admin.php?page=googlesitekit-splash',
				'Configurer'  => [
					'filename'   => 'class-site-kit-configuration-manager.php',
					'class_name' => 'Site_Kit_Configuration_Manager',
				],
			],
			'gravityforms'                => [
				'Name'        => \esc_html__( 'Gravity Forms', 'newspack-plugin' ),
				'Description' => \esc_html__( 'Create custom web forms to capture leads, collect payments, automate your workflows, and build your business online.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'Rocketgenius', 'newspack-plugin' ),
				'PluginURI'   => \esc_url( 'https://www.gravityforms.com/' ),
				'AuthorURI'   => \esc_url( 'https://rocketgenius.com/' ),
			],
			'jetpack'                     => [
				'Name'        => __( 'Jetpack', 'newspack-plugin' ),
				'Description' => \esc_html__( 'Bring the power of the WordPress.com cloud to your self-hosted WordPress. Jetpack enables you to connect your blog to a WordPress.com account to use the powerful features normally only available to WordPress.com users.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'Automattic', 'newspack-plugin' ),
				'PluginURI'   => \esc_url( 'https://jetpack.com/' ),
				'AuthorURI'   => \esc_url( 'https://automattic.com/' ),
				'Download'    => 'wporg',
				'EditPath'    => 'admin.php?page=jetpack',
			],
			'newspack-ads'                => [
				'Name'        => \esc_html__( 'Newspack Ads', 'newspack-plugin' ),
				'Description' => \esc_html__( 'Ads integration.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'Automattic', 'newspack-plugin' ),
				'PluginURI'   => \esc_url( 'https://newspack.com' ),
				'AuthorURI'   => \esc_url( 'https://automattic.com' ),
				'Download'    => 'https://github.com/Automattic/newspack-ads/releases/latest/download/newspack-ads.zip',
			],
			'newspack-blocks'             => [
				'Name'        => \esc_html__( 'Newspack Blocks', 'newspack-plugin' ),
				'Description' => \esc_html__( 'A collection of blocks for news publishers.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'Automattic', 'newspack-plugin' ),
				'PluginURI'   => \esc_url( 'https://newspack.com' ),
				'AuthorURI'   => \esc_url( 'https://automattic.com' ),
				'Download'    => 'https://github.com/Automattic/newspack-blocks/releases/latest/download/newspack-blocks.zip',
			],
			'newspack-content-converter'  => [
				'Name'        => \esc_html__( 'Newspack Content Converter', 'newspack-plugin' ),
				'Description' => \esc_html__( 'Batch conversion of Classic->Gutenberg post conversion.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'Automattic', 'newspack-plugin' ),
				'PluginURI'   => \esc_url( 'https://newspack.com' ),
				'AuthorURI'   => \esc_url( 'https://automattic.com' ),
				'Download'    => 'https://github.com/Automattic/newspack-content-converter/releases/latest/download/newspack-content-converter.zip',
				'Quiet'       => true,
			],
			'newspack-multibranded-site'  => [
				'Name'        => \esc_html__( 'Newspack Multibranded Site', 'newspack-plugin' ),
				'Description' => \esc_html__( 'Brand different content and sections of your site with unique colors and navigation.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'Automattic', 'newspack-plugin' ),
				'PluginURI'   => \esc_url( 'https://newspack.com' ),
				'AuthorURI'   => \esc_url( 'https://automattic.com' ),
				'Download'    => 'https://github.com/Automattic/newspack-multibranded-site/releases/latest/download/newspack-multibranded-site.zip',
				'Quiet'       => true,
			],
			'newspack-network'            => [
				'Name'        => \esc_html__( 'Newspack Network', 'newspack-plugin' ),
				'Description' => \esc_html__( 'Distribute content across a network of Newspack sites.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'Automattic', 'newspack-plugin' ),
				'PluginURI'   => \esc_url( 'https://newspack.com' ),
				'AuthorURI'   => \esc_url( 'https://automattic.com' ),
				'Download'    => 'https://github.com/Automattic/newspack-network/releases/latest/download/newspack-network.zip',
				'Quiet'       => true,
			],
			'newspack-newsletters'        => [
				'Name'        => \esc_html__( 'Newspack Newsletters', 'newspack-plugin' ),
				'Description' => \esc_html__( 'Newsletter authoring using the Gutenberg editor.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'Automattic', 'newspack-plugin' ),
				'PluginURI'   => \esc_url( 'https://newspack.com' ),
				'AuthorURI'   => \esc_url( 'https://automattic.com' ),
				'Download'    => 'https://github.com/Automattic/newspack-newsletters/releases/latest/download/newspack-newsletters.zip',
			],
			'newspack-popups'             => [
				'Name'        => \esc_html__( 'Newspack Campaigns', 'newspack-plugin' ),
				'Description' => \esc_html__( 'Build persuasive call-to-action prompts from scratch and display them as overlays, inline with the story, or above the site header.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'Automattic', 'newspack-plugin' ),
				'PluginURI'   => \esc_url( 'https://newspack.com' ),
				'AuthorURI'   => \esc_url( 'https://automattic.com' ),
				'Download'    => 'https://github.com/Automattic/newspack-popups/releases/latest/download/newspack-popups.zip',
			],
			'newspack-sponsors'           => [
				'Name'        => \esc_html__( 'Newspack Sponsors', 'newspack-plugin' ),
				'Description' => \esc_html__( 'Sponsored and underwritten content for Newspack sites.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'Automattic', 'newspack-plugin' ),
				'PluginURI'   => \esc_url( 'https://newspack.com' ),
				'AuthorURI'   => \esc_url( 'https://automattic.com' ),
				'Download'    => 'https://github.com/Automattic/newspack-sponsors/releases/latest/download/newspack-sponsors.zip',
			],
			'newspack-listings'           => [
				'Name'        => \esc_html__( 'Newspack Listings', 'newspack-plugin' ),
				'Description' => \esc_html__( 'Create reusable content in list form using the Gutenberg editor.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'Automattic', 'newspack-plugin' ),
				'PluginURI'   => \esc_url( 'https://newspack.com' ),
				'AuthorURI'   => \esc_url( 'https://automattic.com' ),
				'Download'    => 'https://github.com/Automattic/newspack-listings/releases/latest/download/newspack-listings.zip',
			],
			'newspack-theme'              => [
				'Name'        => \esc_html__( 'Newspack Theme', 'newspack-plugin' ),
				'Description' => \esc_html__( 'The Newspack theme.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'Newspack', 'newspack-plugin' ),
			],
			'wp-parsely'                  => [
				'Name'        => \esc_html__( 'Parse.ly', 'newspack-plugin' ),
				'Description' => \esc_html__( 'This plugin makes it a snap to add Parse.ly tracking code to your WordPress blog.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'Mike Sukmanowsky ( mike@parsely.com )', 'newspack-plugin' ),
				'PluginURI'   => \esc_url( 'https://www.parsely.com/' ),
				'AuthorURI'   => \esc_url( 'https://www.parsely.com/' ),
				'Download'    => 'wporg',
			],
			'password-protected'          => [
				'Name'        => \esc_html__( 'Password Protected', 'newspack-plugin' ),
				'Description' => \esc_html__( 'A very simple way to quickly password protect your WordPress site with a single password. Please note: This plugin does not restrict access to uploaded files and images and does not work with some caching setups.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'Ben Huson', 'newspack-plugin' ),
				'PluginURI'   => \esc_url( 'https://github.com/benhuson/password-protected/' ),
				'AuthorURI'   => \esc_url( 'https://github.com/benhuson/password-protected/' ),
				'Download'    => 'wporg',
				'EditPath'    => 'options-general.php?page=password-protected',
			],
			'perfmatters'                 => [
				'Name'        => \esc_html__( 'Perfmatters', 'newspack-plugin' ),
				'Description' => \esc_html__( 'Perfmatters is a lightweight performance plugin developed to speed up your WordPress site.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'forgemedia', 'newspack-plugin' ),
				'AuthorURI'   => \esc_url( 'https://forgemedia.io/' ),
				'PluginURI'   => \esc_url( 'https://perfmatters.io/' ),
			],
			'publish-to-apple-news'       => [
				'Name'        => \esc_html__( 'Publish to Apple News', 'newspack-plugin' ),
				'Description' => \esc_html__( 'Export and synchronize posts to Apple format.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'Alley Interactive', 'newspack-plugin' ),
				'AuthorURI'   => \esc_url( 'https://www.alleyinteractive.com' ),
				'PluginURI'   => \esc_url( 'https://github.com/alleyinteractive/apple-news' ),
				'Download'    => 'wporg',
				'EditPath'    => 'admin.php?page=apple_news_index',
				'Configurer'  => [
					'filename'   => 'class-publish-to-apple-news-configuration-manager.php',
					'class_name' => 'Publish_To_Apple_News_Configuration_Manager',
				],
			],
			'pwa'                         => [
				'Name'        => \esc_html__( 'PWA', 'newspack-plugin' ),
				'Description' => \esc_html__( 'Feature plugin to bring Progressive Web App (PWA) capabilities to Core.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'PWA Plugin Contributors', 'newspack-plugin' ),
				'AuthorURI'   => \esc_url( 'https://github.com/xwp/pwa-wp/graphs/contributors' ),
				'PluginURI'   => \esc_url( 'https://github.com/xwp/pwa-wp' ),
				'Download'    => 'wporg',
			],
			'redirection'                 => [
				'Name'        => \esc_html__( 'Redirection', 'newspack-plugin' ),
				'Description' => \esc_html__( 'Manage all your 301 redirects and monitor 404 errors.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'John Godley', 'newspack-plugin' ),
				'AuthorURI'   => \esc_url( 'https://redirection.me/' ),
				'PluginURI'   => \esc_url( 'https://redirection.me/' ),
				'Download'    => 'wporg',
			],
			'woocommerce'                 => [
				'Name'        => \esc_html__( 'WooCommerce', 'newspack-plugin' ),
				'Description' => \esc_html__( 'An eCommerce toolkit that helps you sell anything. Beautifully.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'WordPress.com VIP, XWP, Google, and contributors', 'newspack-plugin' ),
				'PluginURI'   => \esc_url( 'https://woocommerce.com/' ),
				'AuthorURI'   => \esc_url( 'https://woocommerce.com' ),
				'Download'    => 'wporg',
				'EditPath'    => 'admin.php?page=wc-settings',
			],
			'woocommerce-gateway-stripe'  => [
				'Name'        => \esc_html__( 'WooCommerce Stripe Gateway', 'newspack-plugin' ),
				'Description' => \esc_html__( 'Take credit card payments on your store using Stripe.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'WooCommerce', 'newspack-plugin' ),
				'PluginURI'   => \esc_url( 'https://woocommerce.com/' ),
				'AuthorURI'   => \esc_url( 'https://woocommerce.com/' ),
				'Download'    => 'wporg',
				'EditPath'    => 'admin.php?page=wc-settings&tab=checkout&section=stripe',
			],
			'woocommerce-payments'        => [
				'Name'        => \esc_html__( 'WooPayments', 'newspack-plugin' ),
				'Description' => \esc_html__( 'Accept payments via credit card. Manage transactions within WordPress.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'WooCommerce', 'newspack-plugin' ),
				'PluginURI'   => \esc_url( 'https://woocommerce.com/' ),
				'AuthorURI'   => \esc_url( 'https://woocommerce.com/' ),
				'Download'    => 'wporg',
				'EditPath'    => 'admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments',
			],
			'woocommerce-paypal-payments' => [
				'Name'        => \esc_html__( 'WooCommerce PayPal Payments', 'newspack-plugin' ),
				'Description' => \esc_html__( 'PayPal\'s latest complete payments processing solution. Accept PayPal, Pay Later, credit/debit cards, alternative digital wallets local payment types and bank accounts. Turn on only PayPal options or process a full suite of payment methods. Enable global transaction with extensive currency and country coverage.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'WooCommerce', 'newspack-plugin' ),
				'PluginURI'   => \esc_url( 'https://woocommerce.com/' ),
				'AuthorURI'   => \esc_url( 'https://woocommerce.com/' ),
				'Download'    => 'wporg',
				'EditPath'    => 'admin.php?page=wc-settings&tab=checkout&section=ppcp-gateway',
			],
			'woocommerce-name-your-price' => [
				'Name'        => \esc_html__( 'WooCommerce Name Your Price', 'newspack-plugin' ),
				'Description' => \esc_html__( 'WooCommerce Name Your Price allows customers to set their own price for products or donations.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'Kathy Darling', 'newspack-plugin' ),
				'PluginURI'   => \esc_url( 'https://www.woocommerce.com/products/name-your-price/' ),
				'AuthorURI'   => \esc_url( 'https://kathyisawesome.com' ),
			],
			'woocommerce-subscriptions'   => [
				'Name'        => \esc_html__( 'WooCommerce Subscriptions', 'newspack-plugin' ),
				'Description' => \esc_html__( 'Sell products and services with recurring payments in your WooCommerce Store.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'WooCommerce', 'newspack-plugin' ),
				'PluginURI'   => \esc_url( 'https://woocommerce.com/products/woocommerce-subscriptions/' ),
				'AuthorURI'   => \esc_url( 'https://woocommerce.com/' ),
			],
			'wordpress-seo'               => [
				'Name'        => \esc_html__( 'Yoast SEO', 'newspack-plugin' ),
				'Description' => \esc_html__( 'The first true all-in-one SEO solution for WordPress, including on-page content analysis, XML sitemaps and much more.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'Team Yoast', 'newspack-plugin' ),
				'AuthorURI'   => \esc_url( 'https://yoa.st/1uk' ),
				'Download'    => 'wporg',
				'EditPath'    => 'admin.php?page=wpseo_dashboard',
			],
			'complianz-gdpr'              => [
				'Name'        => \esc_html__( 'Complianz - GDPR/CCPA Cookie Consent', 'newspack-plugin' ),
				'Description' => \esc_html__( 'Complianz is a GDPR/CCPA Cookie Consent plugin that supports GDPR, ePrivacy, DSGVO, TTDSG, LGPD, POPIA, APA, RGPD, CCPA/CPRA and PIPEDA with a conditional Cookie Notice and customized Cookie Policy based on the results of the built-in Cookie Scan.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'Really Simple Plugins', 'newspack-plugin' ),
				'AuthorURI'   => \esc_url( 'https://www.complianz.io/' ),
				'PluginURI'   => \esc_url( 'https://wordpress.org/plugins/complianz-gdpr/' ),
				'Download'    => 'wporg',
			],
			'simple-local-avatars'        => [
				'Name'        => \esc_html__( 'Simple Local Avatars', 'newspack-plugin' ),
				'Description' => \esc_html__( 'Adds an avatar upload field to user profiles if the current user has media permissions. Generates requested sizes on demand just like Gravatar! Simple and lightweight.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'Jake Goldman, 10up', 'newspack-plugin' ),
				'AuthorURI'   => \esc_url( 'https://10up.com' ),
				'PluginURI'   => \esc_url( 'https://wordpress.org/plugins/simple-local-avatars/' ),
				'Download'    => 'wporg',
			],
			'distributor'                 => [
				'Name'        => \esc_html__( 'Distributor', 'newspack-plugin' ),
				'Description' => \esc_html__( 'Distributor is a WordPress plugin that makes it easy to syndicate and reuse content across your websites — whether in a single multisite or across the web.', 'newspack-plugin' ),
				'Author'      => '10up',
				'AuthorURI'   => \esc_url( 'https://10up.com' ),
				'PluginURI'   => \esc_url( 'https://distributorplugin.com/' ),
				'Download'    => \esc_url( 'https://github.com/10up/distributor/releases/latest/download/distributor.zip' ),
			],
			'super-cool-ad-inserter'      => [
				'Name'        => \esc_html__( 'Super Cool Ad Inserter Plugin', 'newspack-plugin' ),
				'Description' => \esc_html__( 'Display ads within your article content.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'INN Labs, Automattic', 'newspack-plugin' ),
				'AuthorURI'   => \esc_url( 'https://automattic.com' ),
				'PluginURI'   => \esc_url( 'https://wordpress.org/plugins/super-cool-ad-inserter/' ),
				'Download'    => 'wporg',
			],
			'ad-refresh-control'          => [
				'Name'        => \esc_html__( 'Ad Refresh Control', 'newspack-plugin' ),
				'Description' => \esc_html__( 'Enable Active View refresh for Google Ad Manager ads without needing to modify any code.', 'newspack-plugin' ),
				'Author'      => \esc_html__( 'Gary Thayer, David Green, 10up', 'newspack-plugin' ),
				'AuthorURI'   => \esc_url( 'https://10up.com' ),
				'PluginURI'   => \esc_url( 'https://wordpress.org/plugins/ad-refresh-control/' ),
				'Download'    => 'wporg',
			],
			'ads-txt'                     => [
				'Name'        => \esc_html__( 'Ads.txt Manager', 'newspack-plugin' ),
				'Description' => \esc_html__( 'Create, manage, and validate your ads.txt and app-ads.txt from within WordPress, just like any other content asset.', 'newspack-plugin' ),
				'Author'      => \esc_html__( '10up', 'newspack-plugin' ),
				'PluginURI'   => \esc_url( 'https://wordpress.org/plugins/ads-txt/' ),
				'AuthorURI'   => \esc_url( 'https://10up.com/' ),
				'Download'    => 'wporg',
				'EditPath'    => 'options-general.php?page=adstxt-settings',
			],
			'broadstreet'                 => [
				'Name'        => \esc_html__( 'Broadstreet', 'newspack-plugin' ),
				'Description' => \esc_html__( 'Integrate Broadstreet’s business directory and ad-serving features into your site.', 'newspack-plugin' ),
				'Author'      => 'Broadstreet',
				'AuthorURI'   => \esc_url( 'https://broadstreetads.com/' ),
				'PluginURI'   => \esc_url( 'https://wordpress.org/plugins/broadstreet/' ),
				'Download'    => 'wporg',
			],
			'everlit'                     => [
				'Name'        => esc_html__( 'Everlit', 'newspack-plugin' ),
				'Description' => esc_html__( 'Using a new generation of ultra realistic, AI-driven speech synthesis, we are bringing a backlog of human interest stories, fiction, and knowledge to the present day.', 'newspack-plugin' ),
				'Author'      => 'Everlit',
				'AuthorURI'   => esc_url( 'https://everlit.audio' ),
				'PluginURI'   => esc_url( 'https://everlit.audio' ),
				'Download'    => esc_url( 'https://creator.everlit.audio/wordpress/everlit-wordpress.zip' ),
				'EditPath'    => 'admin.php?page=everlit_settings',
			],
		];

		$default_info = [
			'Name'        => '',
			'Description' => '',
			'Author'      => '',
			'Version'     => '',
			'PluginURI'   => '',
			'AuthorURI'   => '',
			'TextDomain'  => '',
			'DomainPath'  => '',
			'Download'    => '',
			'Status'      => '',
		];

		// Add plugin status info and fill in defaults.
		foreach ( $managed_plugins as $plugin_slug => $managed_plugin ) {
			$status = self::get_managed_plugin_status( $plugin_slug );

			if ( 'newspack-theme' === $plugin_slug ) {
				if ( 'newspack-theme' === \get_stylesheet() ) {
					$status = 'active';
				}
			}
			if ( isset( $managed_plugin['WPCore'] ) ) {
				$status = 'active';
			}

			$managed_plugins[ $plugin_slug ]['Status']      = $status;
			$managed_plugins[ $plugin_slug ]['Slug']        = $plugin_slug;
			$managed_plugins[ $plugin_slug ]['HandoffLink'] = isset( $managed_plugins[ $plugin_slug ]['EditPath'] ) ? \admin_url( $managed_plugins[ $plugin_slug ]['EditPath'] ) : null;
			$managed_plugins[ $plugin_slug ]                = \wp_parse_args( $managed_plugins[ $plugin_slug ], $default_info );
		}

		/**
		 * Filter the list of managed plugins.
		 *
		 * @param array $args Full list of managed plugins.
		 */
		return apply_filters( 'newspack_managed_plugins', $managed_plugins );
	}

	/**
	 * Determine a managed plugin status.
	 *
	 * @param string $plugin_slug Plugin slug.
	 */
	public static function get_managed_plugin_status( $plugin_slug ) {
		if ( Newspack::is_debug_mode() ) {
			return 'active';
		}
		if ( isset( self::$managed_plugin_status_cache[ $plugin_slug ] ) ) {
			return self::$managed_plugin_status_cache[ $plugin_slug ];
		}
		$status            = 'uninstalled';
		$installed_plugins = self::get_installed_plugins();

		if ( isset( $installed_plugins[ $plugin_slug ] ) ) {
			if ( \is_plugin_active( $installed_plugins[ $plugin_slug ] ) ) {
				$status = 'active';
			} else {
				$status = 'inactive';
			}
		}

		// Yoast Premium can be used as a replacement for regular Yoast.
		if ( 'wordpress-seo' === $plugin_slug && 'active' !== $status && isset( $installed_plugins['wordpress-seo-premium'] ) && $installed_plugins['wordpress-seo-premium'] ) {
			if ( \is_plugin_active( $installed_plugins['wordpress-seo-premium'] ) ) {
				$status = 'active';
			}
		}

		// Also check for alpha or epic versions of newspack plugins.
		if ( 'active' !== $status && str_contains( $plugin_slug, 'newspack' ) ) {
			foreach ( $installed_plugins as $slug => $path ) {
				if ( $slug !== $plugin_slug && str_contains( $slug, $plugin_slug ) ) {
					$versions = [
						'alpha',
						'epic',
					];

					foreach ( $versions as $version ) {
						if ( str_contains( $slug, $version ) && \is_plugin_active( $path ) ) {
							$status = 'active';
							break;
						}
					}
				}
			}
		}

		self::$managed_plugin_status_cache[ $plugin_slug ] = $status;
		return $status;
	}

	/**
	 * Reset the per-request memoization of get_managed_plugin_status().
	 *
	 * Tests that mutate plugin install/active state mid-test must call this so
	 * subsequent get_managed_plugin_status() calls re-derive the status.
	 */
	public static function reset_managed_plugin_status_cache() {
		self::$managed_plugin_status_cache = [];
	}

	/**
	 * Get the list of plugins which are supported, but not managed.
	 * These plugins will not be added to the WP Admin plugins screen,
	 * but installing them shouldn't be an issue.
	 */
	private static function get_supported_plugins_slugs() {
		return [
			'gutenberg',
			'classic-widgets',
			'republication-tracker-tool',
			'the-events-calendar',
			'wordpress-seo-premium',
			'gravityformspolls',
			'gravityformsmailchimp',
			'gravityformsstripe',
			'onesignal-free-web-push-notifications',
			'safety-net',
			'web-stories',
			'woocommerce-memberships',
		];
	}

	/**
	 * Get all managed and supported plugins' slugs.
	 */
	public static function get_approved_plugins_slugs() {
		return array_merge(
			array_keys( self::get_managed_plugins() ),
			self::get_supported_plugins_slugs()
		);
	}

	/**
	 * Get info about all the unmanaged plugins that are installed.
	 *
	 * @return array of plugin info.
	 */
	public static function get_unsupported_plugins() {
		$plugins_info            = self::get_installed_plugins_info();
		$managed_plugins         = self::get_managed_plugins();
		$supported_plugins_slugs = self::get_supported_plugins_slugs();
		$unmanaged_plugins       = [];
		foreach ( $plugins_info as $slug => $info ) {
			$is_managed      = ! isset( $managed_plugins[ $slug ] );
			$is_not_newspack = 0 !== strpos( $slug, 'newspack-' );
			$is_active       = \is_plugin_active( $info['Path'] );
			$is_supported    = in_array( $slug, $supported_plugins_slugs );
			if ( ! $is_supported && $is_managed && $is_not_newspack && $is_active ) {
				$unmanaged_plugins[ $slug ] = $info;
			}
		}
		return $unmanaged_plugins;
	}

	/**
	 * Get info about all the required plugins that aren't installed and active.
	 *
	 * @return array of plugin info.
	 */
	public static function get_missing_plugins() {
		$managed_plugins = self::get_managed_plugins();
		$plugins_info    = self::get_installed_plugins_info();
		$missing_plugins = array();
		foreach ( self::$required_plugins as $slug ) {
			if ( 'active' !== self::get_managed_plugin_status( $slug ) ) {
				$missing_plugins[ $slug ] = $managed_plugins[ $slug ];
			}
		}
		return $missing_plugins;
	}

	/**
	 * Determine whether plugin installation is allowed in the current environment.
	 *
	 * @return bool
	 */
	public static function can_install_plugins() {
		if ( ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) ||
			( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Activate a plugin, installing it first if necessary.
	 *
	 * @param string $plugin The plugin slug or URL to the plugin.
	 * @return bool True on success. False on failure or if plugin was already activated.
	 */
	public static function activate( $plugin ) {
		if ( 'newspack-theme' === $plugin ) {
			return Theme_Manager::install_activate_theme();
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_slug = self::get_plugin_slug( $plugin );
		if ( ! $plugin_slug ) {
			return new WP_Error( 'newspack_invalid_plugin', __( 'Invalid plugin.', 'newspack-plugin' ) );
		}

		$installed_plugins = self::get_installed_plugins();

		// Install the plugin if it's not installed already.
		$plugin_installed = isset( $installed_plugins[ $plugin_slug ] );
		if ( ! $plugin_installed ) {
			$plugin_installed = self::install( $plugin );
		}
		if ( \is_wp_error( $plugin_installed ) ) {
			return $plugin_installed;
		}

		// Refresh the installed plugin list if the plugin isn't present because we just installed it.
		if ( ! isset( $installed_plugins[ $plugin_slug ] ) ) {
			$installed_plugins = self::get_installed_plugins();
		}

		if ( ! \is_plugin_active( $installed_plugins[ $plugin_slug ] ) ) {
			$activated = activate_plugin( $installed_plugins[ $plugin_slug ] );
			if ( \is_wp_error( $activated ) ) {
				return new WP_Error( 'newspack_plugin_failed_activation', $activated->get_error_message() );
			}
		}

		// Prevent Yoast from redirecting after installation.
		if (
			in_array( $plugin_slug, [ 'wordpress-seo', 'wordpress-seo-premium' ] )
			&& method_exists( 'WPSEO_Options', 'get' )
		) {
			$redirect = \WPSEO_Options::get( 'should_redirect_after_install_free' );
			if ( $redirect ) {
				\WPSEO_Options::set( 'should_redirect_after_install_free', false );
			}
		}

		return true;
	}

	/**
	 * Deactivate a plugin.
	 *
	 * @param string $plugin The plugin slug (e.g. 'newspack') or path to the plugin file. e.g. ('newspack/newspack.php').
	 * @return bool True on success. False on failure.
	 */
	public static function deactivate( $plugin ) {
		$installed_plugins = self::get_installed_plugins();
		if ( ! in_array( $plugin, $installed_plugins ) && ! isset( $installed_plugins[ $plugin ] ) ) {
			return new WP_Error( 'newspack_plugin_not_installed', __( 'The plugin is not installed.', 'newspack-plugin' ) );
		}

		if ( isset( $installed_plugins[ $plugin ] ) ) {
			$plugin_file = $installed_plugins[ $plugin ];
		} else {
			$plugin_file = $plugin;
		}

		if ( ! \is_plugin_active( $plugin_file ) ) {
			return new WP_Error( 'newspack_plugin_not_active', __( 'The plugin is not active.', 'newspack-plugin' ) );
		}

		deactivate_plugins( $plugin_file );
		if ( \is_plugin_active( $plugin_file ) ) {
			return new WP_Error( 'newspack_plugin_failed_deactivation', __( 'Failed to deactivate plugin.', 'newspack-plugin' ) );
		}
		return true;
	}

	/**
	 * Get a simple list of all installed plugins.
	 *
	 * @return array of 'plugin_slug => plugin_file_path' entries for all installed plugins.
	 */
	public static function get_installed_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = array_reduce( array_keys( \get_plugins() ), array( __CLASS__, 'reduce_plugin_info' ) );
		$themes  = array_reduce( array_keys( \wp_get_themes() ), array( __CLASS__, 'reduce_plugin_info' ) ) ?? [];
		return array_merge( $plugins, $themes );
	}

	/**
	 * Get a list of all installed plugins, with complete info for each.
	 *
	 * @return array of 'plugin_slug => []' entries for all installed plugins.
	 */
	public static function get_installed_plugins_info() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = array_merge( get_plugins(), wp_get_themes() );

		$installed_plugins_info = [];
		foreach ( self::get_installed_plugins() as $key => $path ) {
			$installed_plugins_info[ $key ]         = $plugins[ $path ];
			$installed_plugins_info[ $key ]['Path'] = $path;
		}
		return $installed_plugins_info;
	}

	/**
	 * Parse a plugin slug from the slug or URL to download a plugin.
	 *
	 * @param string $plugin A plugin slug or the URL to a plugin zip file.
	 * @return string|bool Parsed slug on success. False on failure.
	 */
	public static function get_plugin_slug( $plugin ) {
		if ( ! is_string( $plugin ) || empty( $plugin ) ) {
			return false;
		}

		$url = \wp_http_validate_url( $plugin );

		// A plugin slug was passed in, so just return it.
		if ( ! $url ) {
			return $plugin;
		}

		if ( ! stripos( $url, '.zip' ) ) {
			return false;
		}

		$result = preg_match_all( '/\/([^\.\/*]+)/', $url, $matches );
		if ( ! $result ) {
			return false;
		}

		$group = end( $matches );
		$slug  = end( $group );
		return $slug;
	}

	/**
	 * Installs a plugin.
	 *
	 * @param string $plugin Plugin slug or URL to plugin zip file.
	 * @return bool True on success. False on failure.
	 */
	public static function install( $plugin ) {
		if ( ! self::can_install_plugins() ) {
			return new WP_Error( 'newspack_plugin_failed_install', __( 'Plugins cannot be installed.', 'newspack-plugin' ) );
		}

		if ( \wp_http_validate_url( $plugin ) ) {
			return self::install_from_url( $plugin );
		} else {
			return self::install_from_slug( $plugin );
		}
	}

	/**
	 * Uninstall a plugin.
	 *
	 * @param string|array $plugin The plugin slug (e.g. 'newspack') or path to the plugin file. e.g. ('newspack/newspack.php'), or an array thereof.
	 * @return bool True on success. False on failure.
	 */
	public static function uninstall( $plugin ) {
		if ( ! self::can_install_plugins() ) {
			return new WP_Error( 'newspack_plugin_failed_uninstall', __( 'Plugins cannot be uninstalled.', 'newspack-plugin' ) );
		}

		$plugins_to_uninstall = [];
		$installed_plugins    = self::get_installed_plugins();

		if ( ! is_array( $plugin ) ) {
			$plugin = [ $plugin ];
		}

		foreach ( $plugin as $plugin_slug ) {
			if ( ! in_array( $plugin_slug, $installed_plugins ) && ! isset( $installed_plugins[ $plugin_slug ] ) ) {
				return new WP_Error( 'newspack_plugin_failed_uninstall', __( 'The plugin is not installed.', 'newspack-plugin' ) );
			}

			if ( isset( $installed_plugins[ $plugin_slug ] ) ) {
				$plugin_file = $installed_plugins[ $plugin_slug ];
			} else {
				$plugin_file = $plugin_slug;
			}

			// Deactivate plugin before uninstalling.
			self::deactivate( $plugin_file );

			$plugins_to_uninstall[] = $plugin_file;
		}

		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$success = (bool) \delete_plugins( $plugins_to_uninstall );
		if ( $success ) {
			\wp_clean_plugins_cache();
			return true;
		}
		return new WP_Error( 'newspack_plugin_failed_uninstall', __( 'The plugin could not be uninstalled.', 'newspack-plugin' ) );
	}

	/**
	 * Install a plugin by slug.
	 *
	 * @param string $plugin_slug The slug for the plugin.
	 * @return Mixed True on success. WP_Error on failure.
	 */
	protected static function install_from_slug( $plugin_slug ) {
		// Quick check to make sure plugin directory doesn't already exist.
		$plugin_directory = WP_PLUGIN_DIR . '/' . $plugin_slug;
		if ( is_dir( $plugin_directory ) ) {
			return new WP_Error( 'newspack_plugin_already_installed', __( 'The plugin directory already exists.', 'newspack-plugin' ) );
		}

		$managed_plugins = self::get_managed_plugins();
		if ( ! isset( $managed_plugins[ $plugin_slug ] ) ) {
			return new WP_Error(
				'newspack_plugin_failed_install',
				__( 'Plugin not found.', 'newspack-plugin' )
			);
		}

		// Return a useful error if we are unable to get download info for the plugin.
		if ( empty( $managed_plugins[ $plugin_slug ]['Download'] ) ) {
			$error_message = __( 'Newspack cannot install this plugin. You will need to get it from the plugin\'s site and install it manually.', 'newspack-plugin' );
			if ( ! empty( $managed_plugins[ $plugin_slug ]['PluginURI'] ) ) {
				/* translators: %s: plugin URL */
				$error_message = sprintf( __( 'Newspack cannot install this plugin. You will need to get it from <a href="%s">the plugin\'s site</a> and install it manually.', 'newspack-plugin' ), esc_url( $managed_plugins[ $plugin_slug ]['PluginURI'] ) );
			}

			return new WP_Error(
				'newspack_plugin_failed_install',
				$error_message
			);
		}

		// If the plugin has a URL as its Download, install it from there.
		if ( \wp_http_validate_url( $managed_plugins[ $plugin_slug ]['Download'] ) ) {
			return self::install_from_url( $managed_plugins[ $plugin_slug ]['Download'] );
		}

		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}

		// Check WP.org for a download link, and install it from WP.org.
		$plugin_info = \plugins_api(
			'plugin_information',
			[
				'slug'   => $plugin_slug,
				'fields' => [
					'short_description' => false,
					'sections'          => false,
					'requires'          => false,
					'rating'            => false,
					'ratings'           => false,
					'downloaded'        => false,
					'last_updated'      => false,
					'added'             => false,
					'tags'              => false,
					'compatibility'     => false,
					'homepage'          => false,
					'donate_link'       => false,
				],
			]
		);

		if ( \is_wp_error( $plugin_info ) ) {
			return new WP_Error( 'newspack_plugin_failed_install', $plugin_info->get_error_message() );
		}

		return self::install_from_url( $plugin_info->download_link );
	}

	/**
	 * Install a plugin from an arbitrary URL.
	 *
	 * @param string $plugin_url The URL to the plugin zip file.
	 * @return bool True on success. False on failure.
	 */
	protected static function install_from_url( $plugin_url ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		\WP_Filesystem();

		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \WP_Upgrader( $skin );
		$upgrader->init();

		$download = $upgrader->download_package( $plugin_url );
		if ( \is_wp_error( $download ) ) {
			return new WP_Error( 'newspack_plugin_failed_install', $download->get_error_message() );
		}

		// GitHub appends random strings to the end of its downloads.
		// If we asked for foo.zip, make sure the downloaded file is called foo.tmp.
		if ( stripos( $plugin_url, 'github' ) ) {
			$plugin_url_parts  = explode( '/', $plugin_url );
			$desired_file_name = str_replace( '.zip', '', end( $plugin_url_parts ) );
			$new_file_name     = preg_replace( '#(' . $desired_file_name . '.*).tmp#', $desired_file_name . '.tmp', $download );
			rename( $download, $new_file_name ); // phpcs:ignore
			$download = $new_file_name;
		}

		$working_dir = $upgrader->unpack_package( $download );
		if ( \is_wp_error( $working_dir ) ) {
			return new WP_Error( 'newspack_plugin_failed_install', $working_dir->get_error_message() );
		}

		$result = $upgrader->install_package(
			[
				'source'        => $working_dir,
				'destination'   => WP_PLUGIN_DIR,
				'clear_working' => true,
				'hook_extra'    => [
					'type'   => 'plugin',
					'action' => 'install',
				],
			]
		);
		if ( \is_wp_error( $result ) ) {
			return new WP_Error( 'newspack_plugin_failed_install', $result->get_error_message() );
		}

		\wp_clean_plugins_cache();
		return true;
	}

	/**
	 * Reduce get_plugins() info to form 'folder => file'.
	 *
	 * @param array  $plugins Associative array of plugin files to paths.
	 * @param string $key Plugin relative path. Example: newspack/newspack.php.
	 * @return array
	 */
	private static function reduce_plugin_info( $plugins, $key ) {
		$path   = explode( '/', $key );
		$folder = current( $path );

		// Strip version info from key. (e.g. 'woocommerce-gateway-stripe-4.1.2' should just be 'woocommerce-gateway-stripe').
		$folder = preg_replace( '/[\-0-9\.]+$/', '', $folder );

		$plugins[ $folder ] = $key;
		return $plugins;
	}
}
