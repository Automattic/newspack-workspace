<?php
/**
 * Republication Tracker Tool Rewrite Endpoint.
 *
 * @since 1.6.0
 *
 * @package Republication_Tracker_Tool
 */

/**
 * Republication Tracker Tool Rewrite Endpoint class.
 *
 * @since 1.6.0
 */
class Republication_Tracker_Tool_Rewrite_Endpoint {

	/**
	 * Rewrite endpoint.
	 *
	 * @var string
	 */
	public const ENDPOINT = 'republish';

	/**
	 * Constructor.
	 */
	public function __construct() {

		add_action( 'init', array( $this, 'register_endpoint' ) );
		add_filter( 'template_include', array( $this, 'filter_template_include' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts_and_styles' ) );
	}

	/**
	 * Register endpoint.
	 */
	public function register_endpoint() {

		$endpoint = apply_filters( 'republication_tracker_tool_endpoint', self::ENDPOINT );

		// Add rewrite rule to handle /republish/<wordpress-post-url>.
		add_rewrite_rule(
			sprintf( '^%s/(.+)', $endpoint ),
			sprintf( 'index.php?%s=$matches[1]', $endpoint ),
			'top'
		);

		add_rewrite_tag( sprintf( '%%%s%%', $endpoint ), '([^/]+)' );
	}

	/**
	 * Filter template include.
	 *
	 * @param string $template Template.
	 */
	public function filter_template_include( $template ) {

		$rewrite_endpoint = apply_filters( 'republication_tracker_tool_endpoint', self::ENDPOINT );

		$endpoint = get_query_var( $rewrite_endpoint );

		if ( empty( $endpoint ) ) {
			return $template;
		}

		// Get the name of the custom template file.
		$republish_template = REPUBLICATION_TRACKER_TOOL_PATH . 'templates/republish-template.php';

		// Get the post ID from the endpoint.
		$post_id = null;

		// Check if the VIP URL to post ID function exists.
		if ( function_exists( 'wpcom_vip_url_to_postid' ) ) {
			$post_id = wpcom_vip_url_to_postid( $endpoint );
		} else {
			$post_id = url_to_postid( $endpoint ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.url_to_postid_url_to_postid
		}

		// If there is no post ID then redirect to 404.
		if ( ! $post_id ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			include get_query_template( '404' );
			exit;
		}

		// Check if the republish widget is disabled for the post.
		$is_republish_disabled = get_post_meta( $post_id, 'republication-tracker-tool-hide-widget', true );

		// If the republish widget is disabled, redirect to the post.
		if ( ! empty( $is_republish_disabled ) ) {
			wp_safe_redirect( get_permalink( $post_id ) );

			exit;
		}

		$post_type = get_post_type( $post_id );

		$republish_post_types = apply_filters( 'republication_tracker_tool_post_types', array( 'post' ) );

		// If the post type is not in the republish post types, redirect to the post.
		if ( ! in_array( $post_type, $republish_post_types, true ) ) {
			wp_safe_redirect( get_permalink( $post_id ) );

			exit;
		}

		// If the republish template file and post ID exists, use it; otherwise, fall back to the default template.
		if ( file_exists( $republish_template ) && $post_id ) {

			// Set the post ID.
			set_query_var( 'republish_post_id', $post_id );

			// Add canonical URL and noindex to the page.
			add_action(
				'wp_head',
				static function () use ( $post_id ) {
					$canonical_url = get_permalink( $post_id );
					echo wp_sprintf( '<link rel="canonical" href="%s" />', esc_url( $canonical_url ) );
					echo '<meta name="robots" content="noindex, nofollow" />';
				},
				1
			);

			// Return the republish template.
			return $republish_template;
		}

		return $template; // Return the original template for other requests.
	}

	/**
	 * Check if the current page is a republish page.
	 *
	 * @return bool Whether the current page is a republish page.
	 */
	public function is_republish_page() {
		// Get the value of the republish query variable.
		$republish_var = get_query_var( 'republish_post_id' );

		// Check if the republish variable is set and not empty.
		return ! empty( $republish_var );
	}

	/**
	 * Enqueue scripts and styles for the republish page.
	 */
	public function enqueue_scripts_and_styles() {

		if ( ! $this->is_republish_page() ) {
			return;
		}

		// Enqueue the republish page styles.
		wp_enqueue_style(
			'republication-tracker-tool-republish-template',
			REPUBLICATION_TRACKER_TOOL_URL . 'assets/republish-template.css',
			array(),
			REPUBLICATION_TRACKER_TOOL_VERSION
		);

		// Enqueue the clipboard utility first.
		wp_enqueue_script(
			'republication-tracker-tool-clipboard-utils',
			REPUBLICATION_TRACKER_TOOL_URL . 'assets/clipboard-utils.js',
			array(),
			REPUBLICATION_TRACKER_TOOL_VERSION,
			true
		);

		// Enqueue the republish page scripts.
		wp_enqueue_script(
			'republication-tracker-tool-republish-template',
			REPUBLICATION_TRACKER_TOOL_URL . 'assets/republish-template.js',
			array( 'republication-tracker-tool-clipboard-utils' ),
			REPUBLICATION_TRACKER_TOOL_VERSION,
			true
		);
	}
}
