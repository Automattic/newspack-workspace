<?php
/**
 * Plugin Name:     Republication Tracker Tool
 * Description:     Allow readers to share your content via a creative commons license.
 * Author:          INN Labs
 * Author URI:      https://labs.inn.org
 * Text Domain:     republication-tracker-tool
 * Domain Path:     /languages
 * Version:         2.8.2
 *
 * @package         Republication_Tracker_Tool
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
function_exists( 'get_plugin_data' ) || require_once ABSPATH . 'wp-admin/includes/plugin.php';
$plugin_data = get_plugin_data( __FILE__, false, false );

define( 'REPUBLICATION_TRACKER_TOOL_VERSION', $plugin_data['Version'] );
define( 'REPUBLICATION_TRACKER_TOOL_URL', plugin_dir_url( __FILE__ ) );
define( 'REPUBLICATION_TRACKER_TOOL_PATH', plugin_dir_path( __FILE__ ) );

require plugin_dir_path( __FILE__ ) . 'includes/licenses.php';
require plugin_dir_path( __FILE__ ) . 'includes/class-settings.php';
require plugin_dir_path( __FILE__ ) . 'includes/class-media.php';
require plugin_dir_path( __FILE__ ) . 'includes/class-content.php';
require plugin_dir_path( __FILE__ ) . 'includes/class-article-settings.php';
require plugin_dir_path( __FILE__ ) . 'includes/class-widget.php';
require plugin_dir_path( __FILE__ ) . 'includes/compatibility-co-authors-plus.php';
require plugin_dir_path( __FILE__ ) . 'includes/class-republication-rewrite.php';

/**
 * Main initiation class.
 *
 * @since  1.0
 */
final class Republication_Tracker_Tool {

	/**
	 * URL of plugin directory.
	 *
	 * @var    string
	 * @since  1.0
	 */
	protected $url = '';

	/**
	 * Path of plugin directory.
	 *
	 * @var    string
	 * @since  1.0
	 */
	protected $path = '';

	/**
	 * Plugin basename.
	 *
	 * @var    string
	 * @since  1.0
	 */
	protected $basename = '';

	/**
	 * Singleton instance of plugin.
	 *
	 * @var    Republication_Tracker_Tool
	 * @since  1.0
	 */
	protected static $single_instance = null;

	/**
	 * Instance of Republication_Tracker_Tool_Settings
	 *
	 * @since 1.0
	 * @var Republication_Tracker_Tool_Settings
	 */
	protected $settings;

	/**
	 * Instance of Republication_Tracker_Tool_Article_Settings
	 *
	 * @since 1.0
	 * @var Republication_Tracker_Tool_Article_Settings
	 */
	protected $article_settings;

	/**
	 * Instance of Republication_Tracker_Tool_Rewrite_Endpoint
	 *
	 * @since 1.6.0
	 * @var Republication_Tracker_Tool_Rewrite_Endpoint
	 */
	protected $rewrite_endpoint;

	/**
	 * Creates or returns an instance of this class.
	 *
	 * @since   1.0
	 * @return  Republication_Tracker_Tool A single instance of this class.
	 */
	public static function get_instance() {
		if ( null === self::$single_instance ) {
			self::$single_instance = new self();
		}

		return self::$single_instance;
	}

	/**
	 * Sets up our plugin.
	 *
	 * @since  1.0
	 */
	protected function __construct() {
		$this->basename = plugin_basename( __FILE__ );
		$this->url      = plugin_dir_url( __FILE__ );
		$this->path     = plugin_dir_path( __FILE__ );
	}

	/**
	 * Add hooks and filters.
	 * Priority needs to be
	 * < 10 for CPT_Core,
	 * < 5 for Taxonomy_Core,
	 * and 0 for Widgets because widgets_init runs at init priority 1.
	 *
	 * @since  1.0
	 */
	public function hooks() {
		add_action( 'init', array( $this, 'init' ), 0 );
	}

	/**
	 * Init hooks
	 *
	 * @since  1.0
	 */
	public function init() {

		// Load translated strings for plugin.
		load_plugin_textdomain( 'republication-tracker-tool', false, dirname( $this->basename ) . '/languages/' );

		$this->settings         = new Republication_Tracker_Tool_Settings( $this );
		$this->article_settings = new Republication_Tracker_Tool_Article_Settings( $this );
		$this->rewrite_endpoint = new Republication_Tracker_Tool_Rewrite_Endpoint();

		Republication_Tracker_Tool_Media::init();

		add_action( 'widgets_init', array( $this, 'register_widgets' ) );

		add_filter( 'plugin_row_meta', array( $this, '_plugin_row_meta' ), 10, 2 );

		add_filter( 'query_vars', array( $this, 'enable_pixel_query_vars' ) );

		// Fire our pixel if the correct params are set.
		add_filter(
			'template_include',
			function( $template ) {
				// If the params are set, use our pixel functions.
				if ( isset( $_GET['republication-pixel'] ) && isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					include_once plugin_dir_path( __FILE__ ) . 'includes/pixel.php';
					exit;
					// Else, continue with whatever template was being loaded.
				} else {
					return $template;
				}
			},
			99
		);
	}


	/**
	 * Register our widgets.
	 *
	 * @since 1.0
	 */
	public function register_widgets() {
		register_widget( 'Republication_Tracker_Tool_Widget' );
	}


	/**
	 * Activate the plugin.
	 *
	 * @since  1.0
	 */
	public function _activate() {} // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore

	/**
	 * Deactivate the plugin.
	 *
	 * @since  1.0
	 */
	public function _deactivate() {} // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore

	/**
	 * Add plugin row meta.
	 *
	 * @param array  $links An array of the plugin’s metadata, including the version, author, author URI, and plugin URI.
	 * @param string $file Path to the plugin file relative to the plugins directory.
	 * @return array The updated plugin row meta links.
	 */
	public function _plugin_row_meta( $links, $file ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore

		if ( strpos( $file, 'republication-tracker-tool/republication-tracker-tool.php' ) !== false ) {

			$new_links = array(
				'donate'        => '<a href="options-reading.php">Settings</a>',
				'documentation' => '<a href="https://github.com/Automattic/republication-tracker-tool/tree/trunk/docs" target="_blank">Documentation</a>',
			);

			$links = array_merge( $links, $new_links );

		}

		return $links;
	}

	/**
	 * Enable tracking pixel query vars.
	 *
	 * @param array $vars The query vars.
	 * @return array The updated query vars.
	 */
	public function enable_pixel_query_vars( $vars ) {

		$vars[] .= 'republication-pixel';
		$vars[] .= 'GA';
		$vars[] .= 'ga4';
		$vars[] .= 'post';

		return $vars;
	}

	/**
	 * Create tracking pixel HTML markup.
	 *
	 * @param int $post_id ID of the post to track.
	 */
	public static function create_tracking_pixel_markup( $post_id ) {
		$ga4_id = \get_option( 'republication_tracker_tool_analytics_ga4_id' );
		return sprintf(
			// %1$s is the javascript source, %2$s is the post ID, %3$s is the plugins URL
			'<img id="republication-tracker-tool-source" src="%1$s/?republication-pixel=true&post=%2$s%3$s" style="width:1px;height:1px;">',
			esc_attr( get_site_url() ),
			esc_attr( $post_id ),
			$ga4_id ? esc_attr( '&ga4=' . $ga4_id ) : ''
		);
	}

	/**
	 * Create Parse.ly tracking code.
	 *
	 * @param int $post_id ID of the post to track.
	 */
	public static function create_parsely_tracking( $post_id ) {
		$parsely_settings = get_option( 'parsely', [] );
		if ( empty( $parsely_settings ) || ! isset( $parsely_settings['apikey'] ) ) {
			return '';
		}
		$site_id     = $parsely_settings['apikey'];
		$article_url = get_permalink( $post_id );
		return sprintf(
			// %1$s is the original article URL, %2$s is site ID.
			'<script> PARSELY = { autotrack: false, onload: function() { PARSELY.beacon.trackPageView({ url: "%1$s", urlref: window.location.href }); } } </script> <script id="parsely-cfg" src="//cdn.parsely.com/keys/%2$s/p.js"></script>',
			$article_url,
			$site_id
		);
	}

	/**
	 * Create additional tracking code HTML markup.
	 *
	 * @param int $post_id ID of the post to track.
	 * @return string additional tracking code HTML markup.
	 */
	public static function create_additional_tracking_code_markup( $post_id ) {
		$additional_tracking_code = get_option( 'republication_tracker_additional_tracking_code' );
		$additional_tracking_code = str_replace( '{{post-id}}', $post_id, $additional_tracking_code );
		$additional_tracking_code = str_replace( '{{post-url}}', get_permalink( $post_id ), $additional_tracking_code );
		return $additional_tracking_code;
	}

	/**
	 * Get attribution text and tracking code to be added to the content footer
	 *
	 * @param \WP_Post $post The shared post.
	 */
	public static function create_content_footer( $post = null ) {
		$pixel                    = self::create_tracking_pixel_markup( $post->ID );
		$parsely_tracking         = self::create_parsely_tracking( $post->ID );
		$additional_tracking_code = self::create_additional_tracking_code_markup( $post->ID );
		$tracking_html            = htmlentities( $pixel ) . htmlentities( $parsely_tracking ) . htmlentities( $additional_tracking_code );

		/**
		 * Filters the tracking code HTML for the given post.
		 *
		 * @param string $tracking_html The tracking HTML.
		 * @param \WP_Post $post The post object.
		 */
		$tracking_html = apply_filters( 'republication_tracker_tool_tracking_code', $tracking_html, $post );

		$display_attribution = get_option( 'republication_tracker_tool_display_attribution', 'on' );

		$attribution = '';

		if ( 'on' === $display_attribution && null !== $post ) {
			$attribution = self::get_attribution( $post );
		}

		return $attribution . $tracking_html;
	}

	/**
	 * Get attribution text and tracking code to be added to the content footer
	 *
	 * @param \WP_Post $post The shared post.
	 * @param bool     $plain_text Whether to return plain text.
	 */
	public static function get_attribution( \WP_Post $post, $plain_text = false ) {
		$license_key = get_option( 'republication_tracker_tool_license', 'cc-by-nd-4.0' );

		$site_icon_markup = '';
		$site_icon_url    = get_site_icon_url( 150 );
		if ( ! empty( $site_icon_url ) ) {
			$site_icon_markup = sprintf(
				'<img src="%1$s" style="width:1em;height:1em;margin-left:10px;">',
				esc_attr( $site_icon_url )
			);
		}

		if ( isset( REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ] ) ) {
			$license_url         = REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ]['url'];
			$license_description = REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ]['description'];

			if ( $plain_text ) {
				$attribution = sprintf(
					// translators: %1$s is the site title, %2$s is the license description.
					esc_html__( 'This article first appeared on %1$s and is republished here under a %2$s.', 'republication-tracker-tool' ),
					esc_html( get_bloginfo() ),
					$license_description
				);
			} else {
				$attribution = wpautop(
					sprintf(
						// translators: %1$s is a URL, %2$s is the site home URL, %3$s is the site title. %4$s is the license URL, %5$s is the license description.
						esc_html__( 'This <a target="_blank" href="%1$s">article</a> first appeared on <a target="_blank" href="%2$s">%3$s</a> and is republished here under a <a target="_blank" href="%4$s">%5$s</a>.', 'republication-tracker-tool' ),
						get_permalink( $post ),
						home_url(),
						esc_html( get_bloginfo() ),
						$license_url,
						$license_description
					) . htmlentities( $site_icon_markup )
				);
			}
		} elseif ( $plain_text ) {

				$attribution = sprintf(
					// translators: %s is the site title.
					esc_html__( 'This article first appeared on %s.', 'republication-tracker-tool' ),
					esc_html( get_bloginfo() )
				);
		} else {

			$attribution = wpautop(
				sprintf(
					// translators: %1$s is a URL, %2$s is the site home URL, %3$s is the site title.
					esc_html__( 'This <a target="_blank" href="%1$s">article</a> first appeared on <a target="_blank" href="%2$s">%3$s</a>.', 'republication-tracker-tool' ),
					get_permalink( $post ),
					home_url(),
					esc_html( get_bloginfo() )
				) . htmlentities( $site_icon_markup )
			);
		}

		/**
		 * Filters the attribution HTML for the given post.
		 *
		 * @param string $attribution The attribution HTML.
		 * @param \WP_Post $post The post object.
		 * @param bool $plain_text Whether the attribution is plain text.
		 */
		return apply_filters( 'republication_tracker_tool_attribution', $attribution, $post, $plain_text );
	}
}

/**
 * Grab the Republication_Tracker_Tool object and return it.
 * Wrapper for Republication_Tracker_Tool::get_instance().
 *
 * @since  1.0
 * @return Republication_Tracker_Tool  Singleton instance of plugin class.
 */
function Republication_Tracker_Tool() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid, Universal.Files.SeparateFunctionsFromOO.Mixed
	return Republication_Tracker_Tool::get_instance();
}

add_action( 'plugins_loaded', array( Republication_Tracker_Tool(), 'hooks' ) );

// Activation and deactivation.
register_activation_hook( __FILE__, array( Republication_Tracker_Tool(), '_activate' ) );
register_deactivation_hook( __FILE__, array( Republication_Tracker_Tool(), '_deactivate' ) );
