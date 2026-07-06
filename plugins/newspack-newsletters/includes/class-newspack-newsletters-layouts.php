<?php
/**
 * Newspack Newsletter Layouts
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main Newspack Newsletters Class.
 */
final class Newspack_Newsletters_Layouts {
	/**
	 * CPT for Newsletter layouts.
	 * Name is funky because of 20 character restriction.
	 */
	const NEWSPACK_NEWSLETTERS_LAYOUT_CPT = 'newspack_nl_layo_cpt';

	/**
	 * The single instance of the class.
	 *
	 * @var Newspack_Newsletters
	 */
	protected static $instance = null;

	/**
	 * Main Newspack Newsletter Layout Instance.
	 * Ensures only one instance of Newspack Layout Instance is loaded or can be loaded.
	 *
	 * @return Newspack Layout Instance - Main instance.
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
		add_action( 'init', [ __CLASS__, 'register_layout_cpt' ] );
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );
	}

	/**
	 * Register the custom post type for layouts.
	 */
	public static function register_layout_cpt() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}

		$labels = [
			'name'               => __( 'Layouts', 'newspack-newsletters' ),
			'singular_name'      => __( 'Layout', 'newspack-newsletters' ),
			'add_new'            => __( 'Add New Layout', 'newspack-newsletters' ),
			'add_new_item'       => __( 'Add New Layout', 'newspack-newsletters' ),
			'edit_item'          => __( 'Edit Layout', 'newspack-newsletters' ),
			'new_item'           => __( 'New Layout', 'newspack-newsletters' ),
			'view_item'          => __( 'View Layout', 'newspack-newsletters' ),
			'view_items'         => __( 'View Layouts', 'newspack-newsletters' ),
			'search_items'       => __( 'Search Layouts', 'newspack-newsletters' ),
			'not_found'          => __( 'No Layouts found.', 'newspack-newsletters' ),
			'not_found_in_trash' => __( 'No Layouts found in Trash.', 'newspack-newsletters' ),
			'all_items'          => __( 'All Layouts', 'newspack-newsletters' ),
			'item_published'     => __( 'Layout published.', 'newspack-newsletters' ),
			'item_updated'       => __( 'Layout updated.', 'newspack-newsletters' ),
		];

		$cpt_args = [
			'labels'       => $labels,
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => false,
			'show_in_rest' => true,
			// `author` so `_embed` populates `_embedded.author[0]` for the list.
			'supports'     => [ 'editor', 'title', 'custom-fields', 'author' ],
			'taxonomies'   => [],
		];
		\register_post_type( self::NEWSPACK_NEWSLETTERS_LAYOUT_CPT, $cpt_args );
	}

	/**
	 * Register the layout-specific test-send REST route — `wp_mail`s the
	 * rendered HTML directly so layouts never touch an ESP campaign.
	 */
	public static function register_rest_routes() {
		register_rest_route(
			Newspack_Newsletters::API_NAMESPACE,
			'/layouts/(?P<id>\d+)/test',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'rest_send_layout_test_email' ],
				'permission_callback' => [ Newspack_Newsletters::class, 'api_authoring_permissions_check' ],
				'args'                => [
					'id'         => [
						'sanitize_callback' => 'absint',
						'validate_callback' => function ( $id, $request = null, $param = null ) {
							unset( $request, $param );
							$post = get_post( absint( $id ) );
							return $post && self::NEWSPACK_NEWSLETTERS_LAYOUT_CPT === $post->post_type;
						},
					],
					'test_email' => [
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Send a preview of the layout to the supplied email address(es) via
	 * `wp_mail` — bypasses the ESP entirely.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_send_layout_test_email( $request ) {
		$post_id = absint( $request['id'] );
		$raw     = (string) $request->get_param( 'test_email' );
		// Cap at 10 with explode limit 11 so parsing and outbound count are both bounded.
		$emails = array_map(
			static function ( $email ) {
				return sanitize_email( trim( $email ) );
			},
			explode( ',', $raw, 11 )
		);
		$valid = array_slice( array_values( array_filter( $emails, 'is_email' ) ), 0, 10 );

		if ( empty( $valid ) ) {
			return new WP_Error(
				'newspack_newsletters_invalid_email',
				__( 'Please provide at least one valid email address.', 'newspack-newsletters' ),
				[ 'status' => 400 ]
			);
		}

		// Mirrors `update_user_test_emails` so the Testing panel default
		// stays consistent across newsletter and layout sends.
		$user_id   = get_current_user_id();
		$user_info = $user_id ? get_userdata( $user_id ) : null;
		$is_self   = $user_info && 1 === count( $valid ) && $user_info->user_email === $valid[0];
		if ( $user_id && ! $is_self ) {
			update_user_meta( $user_id, 'newspack_nl_test_emails', $valid );
		}

		$html = (string) get_post_meta( $post_id, Newspack_Newsletters::EMAIL_HTML_META, true );
		if ( '' === $html ) {
			return new WP_Error(
				'newspack_newsletters_no_html',
				__( 'This layout has no rendered preview yet — save the layout first, then send a test.', 'newspack-newsletters' ),
				[ 'status' => 409 ]
			);
		}

		$post    = get_post( $post_id );
		$subject = sprintf(
			/* translators: %s: layout title. */
			__( '[Layout preview] %s', 'newspack-newsletters' ),
			$post && $post->post_title ? $post->post_title : __( 'Untitled layout', 'newspack-newsletters' )
		);
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

		// One email per recipient so addresses aren't disclosed to other
		// recipients via the To: header.
		$failed = [];
		foreach ( $valid as $recipient ) {
			$sent = wp_mail( $recipient, $subject, $html, $headers ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
			if ( ! $sent ) {
				$failed[] = $recipient;
			}
		}

		if ( count( $failed ) === count( $valid ) ) {
			return new WP_Error(
				'newspack_newsletters_mail_failed',
				__( 'Failed to send the test email. Please try again.', 'newspack-newsletters' ),
				[ 'status' => 500 ]
			);
		}

		if ( ! empty( $failed ) ) {
			return rest_ensure_response(
				[
					'message'           => sprintf(
						/* translators: %s: comma-separated list of email addresses that failed. */
						__( 'Test email sent, but delivery failed for: %s.', 'newspack-newsletters' ),
						implode( ', ', $failed )
					),
					'failed_recipients' => $failed,
				]
			);
		}

		return rest_ensure_response(
			[
				'message'           => sprintf(
					/* translators: %s: comma-separated list of email addresses. */
					_n(
						'Test email sent to %s.',
						'Test email sent to %s.',
						count( $valid ),
						'newspack-newsletters'
					),
					implode( ', ', $valid )
				),
				'failed_recipients' => [],
			]
		);
	}

	/**
	 * Register custom fields.
	 */
	public static function register_meta() {
		$meta_default_params = [
			'object_subtype' => self::NEWSPACK_NEWSLETTERS_LAYOUT_CPT,
			'show_in_rest'   => true,
			'type'           => 'string',
			'single'         => true,
			'auth_callback'  => '__return_true',
		];
		\register_meta( 'post', 'font_header', $meta_default_params );
		\register_meta( 'post', 'font_body', $meta_default_params );
		\register_meta( 'post', 'background_color', $meta_default_params );
		\register_meta( 'post', 'text_color', $meta_default_params );
		\register_meta( 'post', 'custom_css', $meta_default_params );
		\register_meta( 'post', 'campaign_defaults', $meta_default_params );
		\register_meta( 'post', 'disable_auto_ads', array_merge( $meta_default_params, [ 'type' => 'boolean' ] ) );
	}

	/**
	 * Token replacement for newsletter layouts.
	 *
	 * @param string $content Layout content.
	 * @param array  $extra Associative array of additional tokens to replace.
	 * @return string Content.
	 */
	public static function layout_token_replacement( $content, $extra = [] ) {
		$date               = gmdate( get_option( 'date_format' ) );
		$bg_color           = '#ffffff';
		$text_color         = '#000000';
		$social_links_color = 'black';

		// Check if service provider is Mailchimp.
		if ( 'mailchimp' === Newspack_Newsletters::service_provider() ) {
			$date = '*|DATE:' . get_option( 'date_format' ) . '|*';
		}

		// Check if current theme is a Newspack teme.
		if ( function_exists( 'newspack_setup' ) ) {
			$solid_bg           = get_theme_mod( 'header_solid_background' );
			$header_status      = get_theme_mod( 'header_color' );
			$primary_color_hex  = get_theme_mod( 'primary_color_hex' );
			$header_color_hex   = get_theme_mod( 'header_color_hex' );
			$header_color       = 'default' === $header_status ? $primary_color_hex : $header_color_hex;
			$bg_color           = $solid_bg ? $header_color : '#ffffff';
			$text_color         = newspack_get_color_contrast( $bg_color );
			$social_links_color = '#fff' === $text_color ? 'white' : 'black';
		}

		$sitename_block = '<!-- wp:site-title {"newsletterVisibility":"email"} /-->';

		$sitename_block_center = '<!-- wp:site-title {"textAlign":"center","newsletterVisibility":"email"} /-->';

		$logo_block = '<!-- wp:site-logo {"width":192,"newsletterVisibility":"email"} /-->';

		$logo_block_center = '<!-- wp:site-logo {"align":"center","width":192,"newsletterVisibility":"email"} /-->';

		$date_block = sprintf(
			'<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph -->',
			$date
		);

		$date_block_right = sprintf(
			'<!-- wp:paragraph {"align":"right","newsletterVisibility":"email"} --><p class="has-text-align-right">%s</p><!-- /wp:paragraph -->',
			$date
		);

		$date_block_center = sprintf(
			'<!-- wp:paragraph {"align":"center","newsletterVisibility":"email"} --><p class="has-text-align-center">%s</p><!-- /wp:paragraph -->',
			$date
		);

		$social_links_block = '<!-- wp:social-links {"newsletterVisibility":"email","className":"is-style-filled-' . $social_links_color . '","layout":{"type":"flex","justifyContent":"right"}} --><ul class="wp-block-social-links is-style-filled-' . $social_links_color . '"><!-- wp:social-link {"url":"#","service":"facebook"} /--><!-- wp:social-link {"url":"#","service":"twitter"} /--><!-- wp:social-link {"url":"#","service":"instagram"} /--><!-- wp:social-link {"url":"#","service":"youtube"} /--></ul><!-- /wp:social-links -->';

		$search = array_merge(
			[
				'__LOGO_OR_SITENAME__',
				'__LOGO_OR_SITENAME_CENTER__',
				'__DATE__',
				'__DATE_RIGHT__',
				'__DATE_CENTER__',
				'__SOCIAL_LINKS__',
				'__BG_COLOR__',
				'__TEXT_COLOR__',
			],
			array_keys( $extra )
		);

		$replace = array_merge(
			[
				has_custom_logo() ? $logo_block : $sitename_block,
				has_custom_logo() ? $logo_block_center : $sitename_block_center,
				$date_block,
				$date_block_right,
				$date_block_center,
				$social_links_block,
				'#ffffff' === $bg_color ? '#fafafa' : $bg_color,
				'#ffffff' === $bg_color ? '#000000' : $text_color,
			],
			array_values( $extra )
		);

		return str_replace( $search, $replace, $content );
	}

	/**
	 * Get default layouts. ID is the number in `N.json` so deletions leave
	 * gaps rather than renumbering stored `template_id` references.
	 */
	public static function get_default_layouts() {
		$layouts_base_path = NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'includes/layouts/';
		$layouts           = [];
		foreach ( scandir( $layouts_base_path ) as $layout ) {
			if ( ! preg_match( '/^(\d+)\.json$/', $layout, $matches ) ) {
				continue;
			}
			$layout_id      = (int) $matches[1];
			$decoded_layout = json_decode( file_get_contents( $layouts_base_path . $layout, true ) ); //phpcs:ignore
			if ( ! is_object( $decoded_layout ) || ! property_exists( $decoded_layout, 'content' ) ) {
				continue;
			}
			$title = property_exists( $decoded_layout, 'title' ) ? $decoded_layout->title : '';
			$layouts[] = array(
				'ID'           => $layout_id,
				'post_title'   => $title,
				'post_content' => self::layout_token_replacement( $decoded_layout->content ),
			);
		}
		return $layouts;
	}

	/**
	 * Get all layouts.
	 */
	public static function get_layouts() {
		$layouts_query = new WP_Query(
			[
				'post_type'      => self::NEWSPACK_NEWSLETTERS_LAYOUT_CPT,
				'posts_per_page' => -1,
			]
		);
		$author_cache  = [];
		$user_layouts  = array_map(
			function ( $post ) use ( &$author_cache ) {
				$post->meta = [
					'background_color'  => get_post_meta( $post->ID, 'background_color', true ),
					'text_color'        => get_post_meta( $post->ID, 'text_color', true ),
					'font_body'         => get_post_meta( $post->ID, 'font_body', true ),
					'font_header'       => get_post_meta( $post->ID, 'font_header', true ),
					'custom_css'        => get_post_meta( $post->ID, 'custom_css', true ),
					'campaign_defaults' => get_post_meta( $post->ID, 'campaign_defaults', true ),
					'disable_auto_ads'  => boolval( get_post_meta( $post->ID, 'disable_auto_ads', true ) ),
				];

				// Mirrors the REST v2 `_embed=author` shape; the add-new
				// picker reuses the same chip JSX as the layouts list.
				$author_id = (int) $post->post_author;
				if ( ! isset( $author_cache[ $author_id ] ) ) {
					$author_cache[ $author_id ] = [
						'id'          => $author_id,
						'name'        => $author_id ? get_the_author_meta( 'display_name', $author_id ) : '',
						'avatar_urls' => $author_id ? rest_get_avatar_urls( $author_id ) : (object) [],
					];
				}
				$post->_embedded = [ // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					'author' => [ $author_cache[ $author_id ] ],
				];

				// Migrate layout defaults from legacy meta, if it exists.
				$is_esp_manual = 'manual' === Newspack_Newsletters::service_provider();
				$campaign_defaults = $post->meta['campaign_defaults'];
				$legacy_meta       = json_decode( get_post_meta( $post->ID, 'layout_defaults', true ), true );
				if ( empty( $campaign_defaults ) && ! empty( $legacy_meta ) && ! $is_esp_manual ) {
					$campaign_defaults = [];
					if ( ! empty( $legacy_meta['senderName'] ) ) {
						$campaign_defaults['senderName'] = $legacy_meta['senderName'];
					}
					if ( ! empty( $legacy_meta['senderEmail'] ) ) {
						$campaign_defaults['senderEmail'] = $legacy_meta['senderEmail'];
					}
					$provider      = Newspack_Newsletters::get_service_provider();
					$campaign_info = $provider->extract_campaign_info( $legacy_meta['newsletterData'] ?? null );
					if ( ! empty( $campaign_info['list_id'] ) ) {
						$campaign_defaults['send_list_id'] = $campaign_info['list_id'];
					}
					if ( ! empty( $campaign_info['sublist_id'] ) ) {
						$campaign_defaults['send_sublist_id'] = $campaign_info['sublist_id'];
					}
					if ( ! empty( $campaign_info['senderName'] ) ) {
						$campaign_defaults['senderName'] = $campaign_info['senderName'];
					}
					if ( ! empty( $campaign_info['senderEmail'] ) ) {
						$campaign_defaults['senderEmail'] = $campaign_info['senderEmail'];
					}
					if ( ! empty( $campaign_defaults ) ) {
						$campaign_defaults = wp_json_encode( $campaign_defaults );
						update_post_meta( $post->ID, 'campaign_defaults', $campaign_defaults );
						$post->meta['campaign_defaults'] = $campaign_defaults;
					}
				}

				return $post;
			},
			$layouts_query->get_posts()
		);
		return array_merge(
			$user_layouts,
			self::get_default_layouts(),
			apply_filters( 'newspack_newsletters_templates', [] )
		);
	}
}
Newspack_Newsletters_Layouts::instance();
