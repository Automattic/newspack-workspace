<?php
/**
 * Newspack Newsletter Embed
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Newspack Newsletters Embed Class.
 */
final class Newspack_Newsletters_Embed {
	/**
	 * Allowed HTML tags for rich embeds.
	 *
	 * @var array
	 */
	public static $allowed_html = array(
		'a'          => array(
			'href'  => array(),
			'title' => array(),
		),
		'blockquote' => array(),
		'br'         => array(),
		'em'         => array(),
		'strong'     => array(),
	);
	/**
	 * The single instance of the class.
	 *
	 * @var Newspack_Newsletters_Embed
	 */
	protected static $instance = null;

	/**
	 * Main Newspack Newsletter Embed Instance.
	 * Ensures only one instance of Newspack Embed Instance is loaded or can be loaded.
	 *
	 * @return Newspack Embed Instance - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'rest_pre_echo_response', [ __CLASS__, 'add_sanitized_html' ], 10, 3 );
	}

	/**
	 * Add sanitized HTML to oEmbed API response.
	 *
	 * @param object          $response Response data to send to the client.
	 * @param WP_REST_Server  $server   Server instance.
	 * @param WP_REST_Request $request  Request used to generate the response.
	 * 
	 * @return array Response data to send to the client.
	 */
	public static function add_sanitized_html( $response, $server, $request ) {
		if ( '/oembed/1.0/proxy' === $request->get_route() && isset( $response->html ) ) {
			$response->sanitized_html = wp_kses( $response->html, self::$allowed_html );
		}
		return $response;
	}
}
Newspack_Newsletters_Embed::instance();
