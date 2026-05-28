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
	}

	/**
	 * Register the custom post type for layouts.
	 */
	public static function register_layout_cpt() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}

		$cpt_args = [
			'public'       => false,
			'show_in_rest' => true,
			'supports'     => [ 'editor', 'title', 'custom-fields' ],
			'taxonomies'   => [],
		];
		\register_post_type( self::NEWSPACK_NEWSLETTERS_LAYOUT_CPT, $cpt_args );
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
	 * Get default layouts.
	 */
	public static function get_default_layouts() {
		$layouts_base_path = NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'includes/layouts/';
		$layouts           = [];
		// 1-indexed, because 0 denotes a blank layout.
		$layout_id = 1;
		foreach ( scandir( $layouts_base_path ) as $layout ) {
			if ( strpos( $layout, '.json' ) !== false ) {
				$decoded_layout  = json_decode( file_get_contents( $layouts_base_path . $layout, true ) ); //phpcs:ignore
				$title          = '';
				if ( property_exists( $decoded_layout, 'title' ) ) {
					$title = $decoded_layout->title;
				}
				$layouts[] = array(
					'ID'           => $layout_id,
					'post_title'   => $title,
					'post_content' => self::layout_token_replacement( $decoded_layout->content ),
				);
				$layout_id++;
			}
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
		$user_layouts  = array_map(
			function ( $post ) {
				$post->meta = [
					'background_color'  => get_post_meta( $post->ID, 'background_color', true ),
					'text_color'        => get_post_meta( $post->ID, 'text_color', true ),
					'font_body'         => get_post_meta( $post->ID, 'font_body', true ),
					'font_header'       => get_post_meta( $post->ID, 'font_header', true ),
					'custom_css'        => get_post_meta( $post->ID, 'custom_css', true ),
					'campaign_defaults' => get_post_meta( $post->ID, 'campaign_defaults', true ),
					'disable_auto_ads'  => boolval( get_post_meta( $post->ID, 'disable_auto_ads', true ) ),
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
