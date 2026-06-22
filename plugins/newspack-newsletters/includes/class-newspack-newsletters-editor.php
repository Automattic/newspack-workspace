<?php
/**
 * Newspack Newsletter Editor
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main Newspack Newsletters Editor Class.
 * Everything needed to turn the Post editor into a Newsletter editor.
 */
final class Newspack_Newsletters_Editor {

	/**
	 * The single instance of the class.
	 *
	 * @var Newspack_Newsletters_Editor
	 */
	protected static $instance = null;

	/**
	 * Closure for excerpt filtering that can be added and removed.
	 *
	 * @var newspack_newsletters_excerpt_length_filter
	 */
	public static $newspack_newsletters_excerpt_length_filter = null;

	/**
	 * Main Newspack Newsletter Editor Instance.
	 * Ensures only one instance of Newspack Editor Instance is loaded or can be loaded.
	 *
	 * @return Newspack Editor Instance - Main instance.
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
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_filter( 'block_editor_settings_all', [ __CLASS__, 'disable_autosave' ], 10, 2 );
		add_filter( 'block_editor_settings_all', [ __CLASS__, 'override_email_editor_settings' ], 10, 2 );
		add_action( 'the_post', [ __CLASS__, 'strip_editor_modifications' ] );
		add_action( 'after_setup_theme', [ __CLASS__, 'newspack_font_sizes' ], 11 );
		add_filter( 'wp_theme_json_data_theme', [ __CLASS__, 'override_theme_json_for_email_editor' ] );
		add_action( 'enqueue_block_assets', [ __CLASS__, 'enqueue_block_assets' ] );
		add_filter( 'block_categories_all', [ __CLASS__, 'add_custom_block_category' ] );
		add_filter( 'allowed_block_types_all', [ __CLASS__, 'newsletters_allowed_block_types' ], 10, 2 );
		add_action( 'rest_post_query', [ __CLASS__, 'maybe_filter_excerpt_length' ], 10, 2 );
		add_action( 'rest_post_query', [ __CLASS__, 'rest_post_query_filter' ], 10, 2 );
		add_action( 'rest_api_init', [ __CLASS__, 'add_newspack_extra_info' ] );
		add_filter( 'the_posts', [ __CLASS__, 'maybe_reset_excerpt_length' ] );
		add_filter( 'should_load_remote_block_patterns', [ __CLASS__, 'strip_block_patterns' ] );
	}

	/**
	 * Register custom fields.
	 */
	public static function register_meta() {
		foreach ( self::get_email_editor_cpts() as $cpt ) {
			\register_meta(
				'post',
				Newspack_Newsletters::EMAIL_HTML_META,
				[
					'object_subtype' => $cpt,
					'show_in_rest'   => [
						'schema' => [
							'context' => [ 'edit' ],
						],
					],
					'type'           => 'string',
					'single'         => true,
					'auth_callback'  => '__return_true',
				]
			);
		}
	}

	/**
	 * Get post types which should be edited using the email editor.
	 */
	private static function get_email_editor_cpts() {
		$email_cpts = [
			Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
			Newspack_Newsletters\Ads::CPT,
			Newspack_Newsletters_Layouts::NEWSPACK_NEWSLETTERS_LAYOUT_CPT,
		];
		return apply_filters( 'newspack_newsletters_email_editor_cpts', $email_cpts );
	}

	/**
	 * Is the editor editing an email?
	 *
	 * @param int $post_id Optional post ID to check.
	 */
	public static function is_editing_email( $post_id = null ) {
		$post_id = empty( $post_id ) ? get_the_ID() : $post_id;
		return in_array( get_post_type( $post_id ), self::get_email_editor_cpts() );
	}

	/**
	 * Is the current request an email editor admin page?
	 *
	 * Uses URL params rather than get_the_ID() to avoid false positives when
	 * setup_postdata() has been called with a newsletter post during block
	 * rendering on non-newsletter pages (e.g. a Homepage Articles block
	 * configured to display newsletter posts).
	 *
	 * @return bool
	 */
	public static function is_email_editor_request() {
		global $pagenow;
		$email_editor_cpts = self::get_email_editor_cpts();
		$is_editing_email  = 'post.php' === $pagenow && isset( $_GET['post'] ) && self::is_editing_email( absint( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_creating_email = 'post-new.php' === $pagenow && isset( $_GET['post_type'] ) && in_array( $_GET['post_type'], $email_editor_cpts ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $is_editing_email || $is_creating_email;
	}

	/**
	 * Get CSS rules for color palette.
	 *
	 * @param string $container_selector Optional selector to prefix as a container to every rule.
	 *
	 * @return string CSS rules.
	 */
	public static function get_color_palette_css( $container_selector = '' ) {
		$rules = [];
		// Add `.has-{color-name}-color` rules for each palette color.
		$color_palette = json_decode( get_option( Newspack_Newsletters::NEWSPACK_NEWSLETTERS_PALETTE_META, false ), true );
		if ( ! empty( $color_palette ) ) {
			foreach ( $color_palette as $color_name => $color_value ) {
				if ( '' === $color_name || '' === $color_value ) {
					continue;
				}
				$rules[] = '.has-' . esc_html( $color_name ) . '-color { color: ' . esc_html( $color_value ) . '; }';
			}
		}
		if ( $container_selector ) {
			$container_selector = esc_html( $container_selector );
			$rules              = array_map(
				function ( $rule ) use ( $container_selector ) {
					return $container_selector . ' ' . $rule;
				},
				$rules
			);
		}
		return implode( "\n", $rules );
	}

	/**
	 * Disable autosaving in the editor for newsletter posts.
	 * For currently unknown reasons, autosaves for this CPT result in true saves
	 * instead of creating an autosave revision, which could persist unintended changes.
	 *
	 * @param array                   $editor_settings      Default editor settings.
	 * @param WP_Block_Editor_Context $block_editor_context The current block editor context.
	 *
	 * @return array
	 */
	public static function disable_autosave( $editor_settings, $block_editor_context ) {
		if ( isset( $block_editor_context->post->post_type ) && Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT === $block_editor_context->post->post_type ) {
			$editor_settings['autosaveInterval'] = 999999;
		}
		return $editor_settings;
	}

	/**
	 * Override the editor settings and width for the newsletter editor.
	 *
	 * Block themes provide layout settings (contentSize, wideSize) via
	 * block_editor_settings_all that control block widths in the editor.
	 * For the newsletter editor, all blocks should use the email max-width.
	 *
	 * This function also hides the 'font' family option from the Typography panel.
	 *
	 * @param array                   $editor_settings      Default editor settings.
	 * @param WP_Block_Editor_Context $block_editor_context The current block editor context.
	 *
	 * @return array
	 */
	public static function override_email_editor_settings( $editor_settings, $block_editor_context ) {
		if (
			! isset( $block_editor_context->post->post_type ) ||
			! in_array( $block_editor_context->post->post_type, self::get_email_editor_cpts(), true )
		) {
			return $editor_settings;
		}

		$email_width = '600px';

		// Override the layout settings used by the editor JS.
		if ( isset( $editor_settings['__experimentalFeatures']['layout'] ) ) {
			$editor_settings['__experimentalFeatures']['layout']['contentSize'] = $email_width;
			$editor_settings['__experimentalFeatures']['layout']['wideSize']    = $email_width;
		}

		// Hide the font-family picker — registered fonts (including those added
		// via Appearance > Fonts) cannot be relied on to render in email clients.
		if ( isset( $editor_settings['__experimentalFeatures']['typography'] ) ) {
			$editor_settings['__experimentalFeatures']['typography']['fontFamilies'] = [];
		}

		return $editor_settings;
	}

	/**
	 * Remove all editor enqueued assets besides this plugins' and disable some editor features.
	 * This is to prevent theme styles being loaded in the editor.
	 */
	public static function strip_editor_modifications() {
		if ( ! self::is_email_editor_request() ) {
			return;
		}

		$allowed_actions = [
			__CLASS__ . '::enqueue_block_assets',
			'newspack_enqueue_scripts',
			'wp_enqueue_editor_format_library_assets',
		];

		if ( isset( $GLOBALS['coauthors_plus'] ) ) {
			$hash              = spl_object_hash( $GLOBALS['coauthors_plus'] );
			$allowed_actions[] = $hash . 'enqueue_sidebar_plugin_assets';
		}

		if ( is_plugin_active( 'remote-data-blocks/remote-data-blocks.php' ) ) {
			$allowed_actions[] = 'RemoteDataBlocks\Editor\BlockManagement\BlockRegistration::enqueue_block_assets';
			$allowed_actions[] = 'RemoteDataBlocks\Editor\PatternEditor\PatternEditor::enqueue_block_editor_assets';
			$allowed_actions[] = 'RemoteDataBlocks\Editor\Assets::enqueue_build_assets';
		}

		/**
		 * Filters allowed 'enqueue_block_editor_assets' actions inside a newsletter editor.
		 *
		 * @param string[] $allowed_actions Array of allowed actions.
		 */
		$allowed_actions = apply_filters(
			'newspack_newsletters_allowed_editor_actions',
			$allowed_actions
		);

		$enqueue_block_assets_filters = $GLOBALS['wp_filter']['enqueue_block_assets']->callbacks;
		foreach ( $enqueue_block_assets_filters as $index => $filter ) {
			$action_handlers = array_keys( $filter );
			foreach ( $action_handlers as $handler ) {
				if ( ! in_array( $handler, $allowed_actions, true ) ) {
					remove_action( 'enqueue_block_assets', $handler, $index );
				}
			}
		}

		remove_editor_styles();
		add_theme_support( 'editor-gradient-presets', array() );
		add_theme_support( 'disable-custom-gradients' );

		$block_patterns_registry = \WP_Block_Patterns_Registry::get_instance();
		if ( $block_patterns_registry->is_registered( 'core/social-links-shared-background-color' ) ) {
			unregister_block_pattern( 'core/social-links-shared-background-color' );
		}
	}

	/**
	 * Remove Core's Remote Block patterns.
	 *
	 * @param boolean $should_load_remote Whether to load remote block patterns.
	 *
	 * @return boolean Whether to load remote block patterns.
	 */
	public static function strip_block_patterns( $should_load_remote ) {
		if ( self::is_editing_email() ) {
			return false;
		}

		return $should_load_remote;
	}

	/**
	 * Define Editor Font Sizes.
	 */
	public static function newspack_font_sizes() {
		if ( ! self::is_email_editor_request() ) {
			return;
		}
		add_theme_support(
			'editor-font-sizes',
			[
				[
					'name' => _x( 'Small', 'font size name', 'newspack-newsletters' ),
					'size' => 12,
					'slug' => 'small',
				],
				[
					'name' => _x( 'Medium', 'font size name', 'newspack-newsletters' ),
					'size' => 16,
					'slug' => 'medium',
				],
				[
					'name' => _x( 'Large', 'font size name', 'newspack-newsletters' ),
					'size' => 24,
					'slug' => 'large',
				],
				[
					'name' => _x( 'Extra Large', 'font size name', 'newspack-newsletters' ),
					'size' => 36,
					'slug' => 'x-large',
				],
			]
		);
	}

	/**
	 * Override theme.json data for the email editor.
	 *
	 * Block themes inject styles through theme.json global styles, which
	 * are not removed by remove_editor_styles(). This filter replaces
	 * theme values (clamp, rem, CSS custom properties) with email-safe
	 * pixel values in the newsletter editor context.
	 *
	 * @param WP_Theme_JSON_Data $theme_json The theme.json data.
	 * @return WP_Theme_JSON_Data
	 */
	public static function override_theme_json_for_email_editor( $theme_json ) {
		if ( ! self::is_email_editor_request() ) {
			return $theme_json;
		}

		$email_overrides = [
			'version'  => 3,
			'settings' => [
				'typography' => [
					'fluid'     => false,
					'fontSizes' => [
						[
							'name'  => _x( 'Small', 'font size name', 'newspack-newsletters' ),
							'size'  => '12px',
							'slug'  => 'small',
							'fluid' => false,
						],
						[
							'name'  => _x( 'Medium', 'font size name', 'newspack-newsletters' ),
							'size'  => '16px',
							'slug'  => 'medium',
							'fluid' => false,
						],
						[
							'name'  => _x( 'Large', 'font size name', 'newspack-newsletters' ),
							'size'  => '24px',
							'slug'  => 'large',
							'fluid' => false,
						],
						[
							'name'  => _x( 'Extra Large', 'font size name', 'newspack-newsletters' ),
							'size'  => '36px',
							'slug'  => 'x-large',
							'fluid' => false,
						],
					],
				],
				'spacing'    => [
					'spacingSizes' => [
						[
							'name' => '1',
							'size' => '8px',
							'slug' => '20',
						],
						[
							'name' => '2',
							'size' => '16px',
							'slug' => '30',
						],
						[
							'name' => '3',
							'size' => '24px',
							'slug' => '40',
						],
						[
							'name' => '4',
							'size' => '32px',
							'slug' => '50',
						],
						[
							'name' => '5',
							'size' => '32px',
							'slug' => '60',
						],
						[
							'name' => '6',
							'size' => '48px',
							'slug' => '70',
						],
						[
							'name' => '7',
							'size' => '64px',
							'slug' => '80',
						],
					],
				],
				'layout'     => [
					'contentSize' => '600px',
					'wideSize'    => '600px',
				],
			],
		];

		// Only override button element styles for block themes — classic themes
		// use their own neutral defaults and don't need the opinionated blue.
		if ( wp_is_block_theme() ) {
			$primary_color = '#36f';
			if ( method_exists( '\Newspack\Lite_Site', 'get_primary_color' ) ) {
				$primary_color = Newspack\Lite_Site::get_primary_color();
			}
			$email_overrides['styles'] = [
				'elements' => [
					'button' => [
						'color'   => [
							'background' => $primary_color,
							'text'       => '#fff',
						],
						'border'  => [
							'radius' => '5px',
						],
						'spacing' => [
							'padding' => [
								'top'    => '12px',
								'bottom' => '12px',
								'left'   => '24px',
								'right'  => '24px',
							],
						],
					],
				],
			];
		}

		return $theme_json->update_with( $email_overrides );
	}

	/**
	 * Add the "Newspack" block category.
	 *
	 * @param array $block_categories Default block categories.
	 * @return array
	 */
	public static function add_custom_block_category( $block_categories ) {
		array_unshift(
			$block_categories,
			[
				'slug'  => 'newspack',
				'title' => 'Newspack',
			]
		);

		return $block_categories;
	}

	/**
	 * Restrict block types for Newsletter CPT.
	 *
	 * @param array   $allowed_block_types default block types.
	 * @param WP_Post $post the post to consider.
	 */
	public static function newsletters_allowed_block_types( $allowed_block_types, $post ) {
		if ( ! self::is_editing_email() ) {
			return $allowed_block_types;
		}
		$allowed_block_types = array(
			'core/spacer',
			'core/block',
			'core/group',
			'core/paragraph',
			'core/embed',
			'core/heading',
			'core/column',
			'core/columns',
			'core/buttons',
			'core/button',
			'core/image',
			'core/separator',
			'core/list',
			'core/list-item',
			'core/quote',
			'core/site-logo',
			'core/site-tagline',
			'core/site-title',
			'core/social-links',
			'core/social-link',
			'newspack-newsletters/ad',
			'newspack-newsletters/posts-inserter',
			'newspack-newsletters/share',
			'remote-data-blocks/foundation-event',
			'remote-data-blocks/foundation-events',
			'remote-data-blocks/foundation-location',
			'remote-data-blocks/foundation-locations',
			'remote-data-blocks/foundation-movie',
			'remote-data-blocks/foundation-movies',
		);
		/**
		 * Filters the allowed block types for the Newsletter CPT.
		 *
		 * @param array   $allowed_block_types default block types.
		 * @param WP_Post $post the post to consider.
		 */
		return apply_filters( 'newspack_newsletters_allowed_block_types', $allowed_block_types, $post );
	}

	/**
	 * Build the `newspack_email_editor_data` payload shared by the editor and any
	 * surface that previews newsletter blocks (e.g. the admin-shell layouts list).
	 *
	 * @return array
	 */
	public static function get_email_editor_data() {
		// Remove the Ads CPT - it does not need MJML handling since ads
		// will be injected into email content before it's converted to MJML.
		$mjml_handling_post_types = array_values( array_diff( self::get_email_editor_cpts(), [ Newspack_Newsletters\Ads::CPT ] ) );
		$provider                 = Newspack_Newsletters::get_service_provider();
		$conditional_tag_support  = false;

		if ( $provider && ( self::is_editing_newsletter() || self::is_editing_newsletter_ad() || self::is_editing_layout() ) ) {
			$conditional_tag_support = $provider::get_conditional_tag_support();
		}

		return [
			'email_html_meta'                => Newspack_Newsletters::EMAIL_HTML_META,
			'mjml_handling_post_types'       => $mjml_handling_post_types,
			'newsletter_post_type'           => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
			'current_post_type'              => get_post_type(),
			'conditional_tag_support'        => $conditional_tag_support,
			'sponsors_flag_hex'              => get_theme_mod( 'sponsored_flag_hex', '#FED850' ),
			'sponsors_flag_text_color'       => function_exists( 'newspack_get_color_contrast' ) ? newspack_get_color_contrast( \get_theme_mod( 'sponsored_flag_hex', '#FED850' ) ) : 'black',
			'labels'                         => [
				'continue_reading_label' => __( 'Continue reading…', 'newspack-newsletters' ),
				'byline_prefix_label'    => __( 'By ', 'newspack-newsletters' ),
				'byline_connector_label' => __( 'and ', 'newspack-newsletters' ),
			],
			'supported_social_icon_services' => Newspack_Newsletters_Renderer::get_supported_social_icons_services(),
			'supported_esps'                 => Newspack_Newsletters::get_supported_providers(),
			'merge_tags'                     => $provider
				? $provider::get_merge_tags()
				: Newspack_Newsletters_Service_Provider::get_merge_tags(),
			'sample_assets_url'              => plugins_url( '../assets/sample-posts/', __FILE__ ),
		];
	}

	/**
	 * Load up common JS/CSS for newsletter editor.
	 */
	public static function enqueue_block_assets() {
		if ( ! is_admin() ) {
			return;
		}

		$email_editor_data = self::get_email_editor_data();

		if ( self::is_editing_email() ) {
			wp_register_style(
				'newspack-newsletters',
				plugins_url( '../dist/editor.css', __FILE__ ),
				[],
				filemtime( NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'dist/editor.css' )
			);
			wp_style_add_data( 'newspack-newsletters', 'rtl', 'replace' );
			wp_enqueue_style( 'newspack-newsletters' );

			wp_add_inline_style( 'newspack-newsletters', self::get_color_palette_css( '.editor-styles-wrapper' ) );

			$editor_asset = include NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'dist/editor.asset.php';
			\wp_enqueue_script(
				'newspack-newsletters-editor',
				plugins_url( '../dist/editor.js', __FILE__ ),
				$editor_asset['dependencies'],
				filemtime( NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'dist/editor.js' ),
				true
			);
			wp_localize_script( 'newspack-newsletters-editor', 'newspack_email_editor_data', $email_editor_data );
			do_action( 'newspack_newsletters_enqueue_block_editor_assets' );
		}

		if ( self::is_editing_newsletter_ad() ) {
			$ads_page_asset = include NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'dist/adsEditor.asset.php';
			\wp_enqueue_script(
				'newspack-newsletters-ads-page',
				plugins_url( '../dist/adsEditor.js', __FILE__ ),
				$ads_page_asset['dependencies'],
				filemtime( NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'dist/adsEditor.js' ),
				true
			);
		}

		if ( self::is_editing_newsletter() || self::is_editing_layout() ) {
			wp_localize_script(
				'newspack-newsletters-editor',
				'newspack_newsletters_data',
				[
					'is_service_provider_configured' => Newspack_Newsletters::is_service_provider_configured(),
					'service_provider'               => Newspack_Newsletters::service_provider(),
					'user_test_emails'               => self::get_current_user_test_emails(),
					'labels'                         => $provider ? $provider::get_labels() : [],
				]
			);
			wp_register_style(
				'newspack-newsletters-newsletter-editor',
				plugins_url( '../dist/newsletterEditor.css', __FILE__ ),
				[],
				filemtime( NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'dist/newsletterEditor.css' )
			);
			wp_style_add_data( 'newspack-newsletters-newsletter-editor', 'rtl', 'replace' );
			wp_enqueue_style( 'newspack-newsletters-newsletter-editor' );
			$newsletter_editor_asset = include NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'dist/newsletterEditor.asset.php';
			\wp_enqueue_script(
				'newspack-newsletters-newsletter-editor',
				plugins_url( '../dist/newsletterEditor.js', __FILE__ ),
				$newsletter_editor_asset['dependencies'],
				filemtime( NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'dist/newsletterEditor.js' ),
				true
			);
			if ( class_exists( 'Newspack_Newsletters_Mailchimp_Default_Footer' ) ) {
				\wp_localize_script(
					'newspack-newsletters-newsletter-editor',
					'newspack_newsletters_editor_data',
					[
						'mailchimp_default_footer' => wp_kses_post( Newspack_Newsletters_Mailchimp_Default_Footer::get_footer_content() ),
					]
				);
			}
			$ads_editor_asset = include NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'dist/newsletterAdsEditor.asset.php';
			\wp_enqueue_script(
				'newspack-newsletters-ads-editor',
				plugins_url( '../dist/newsletterAdsEditor.js', __FILE__ ),
				$ads_editor_asset['dependencies'],
				filemtime( NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'dist/newsletterAdsEditor.js' ),
				true
			);
		}

		// If it's a reusable block, register this plugin's blocks.
		if ( 'wp_block' === get_post_type() ) {
			$editor_blocks_asset = include NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'dist/editorBlocks.asset.php';
			\wp_enqueue_script(
				'newspack-newsletters-editor-blocks',
				plugins_url( '../dist/editorBlocks.js', __FILE__ ),
				$editor_blocks_asset['dependencies'],
				filemtime( NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'dist/editorBlocks.js' ),
				true
			);
			wp_register_style(
				'newspack-newsletters-editor-blocks',
				plugins_url( '../dist/editorBlocks.css', __FILE__ ),
				[],
				filemtime( NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'dist/editorBlocks.css' )
			);
			wp_style_add_data( 'newspack-newsletters-editor-blocks', 'rtl', 'replace' );
			wp_enqueue_style( 'newspack-newsletters-editor-blocks' );
			// Localized data for the editor.
			wp_localize_script( 'newspack-newsletters-editor-blocks', 'newspack_email_editor_data', $email_editor_data );
		}
	}

	/**
	 * Is editing a newsletter?
	 */
	private static function is_editing_newsletter() {
		return Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT === get_post_type();
	}

	/**
	 * Is editing a newsletter ad?
	 */
	private static function is_editing_newsletter_ad() {
		return Newspack_Newsletters\Ads::CPT === get_post_type();
	}

	/**
	 * Is editing a layout?
	 */
	private static function is_editing_layout() {
		return Newspack_Newsletters_Layouts::NEWSPACK_NEWSLETTERS_LAYOUT_CPT === get_post_type();
	}

	/**
	 * If excerpt length is set in Post Inserter block attributes, override the site's excerpt length using the setting.
	 *
	 * @param array           $args Request arguments.
	 * @param WP_REST_Request $request The original REST request params.
	 *
	 * @return array Unmodified request args.
	 */
	public static function maybe_filter_excerpt_length( $args, $request ) {
		$params = $request->get_params();

		if ( isset( $params['excerpt_length'] ) ) {
			self::filter_excerpt_length( intval( $params['excerpt_length'] ) );
		}

		return $args;
	}

	/**
	 * Update the Post Inserter query.
	 *
	 * @param array           $args Request arguments.
	 * @param WP_REST_Request $request The original REST request params.
	 *
	 * @return array Filtered request args.
	 */
	public static function rest_post_query_filter( $args, $request ) {
		$params = $request->get_params();

		// Ignore sticky posts in query.
		$args['ignore_sticky_posts'] = true;

		// If Posts Inserter is set to hide sponsored content, add a tax query to exclude sponsored posts.
		if ( ! empty( $params['exclude_sponsors'] ) && class_exists( '\Newspack_Sponsors\Core' ) ) {
			if ( empty( $args['tax_query'] ) ) {
				$args['tax_query'] = []; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			}

			// Exclude posts with direct sponsors.
			$args['tax_query'][] = [
				'taxonomy' => \Newspack_Sponsors\Core::NEWSPACK_SPONSORS_TAX,
				'operator' => 'NOT EXISTS',
			];

			// Exclude posts with sponsored terms, too.
			$sponsored_terms = \Newspack_Sponsors\get_all_sponsored_terms();
			if ( ! empty( $sponsored_terms ) ) {
				$args['tax_query']['relation'] = 'AND';
				foreach ( $sponsored_terms as $taxonomy => $term_ids ) {
					$args['tax_query'][] = [
						'taxonomy' => $taxonomy,
						'terms'    => $term_ids,
						'operator' => 'NOT IN',
					];
				}
			}
		}

		return $args;
	}

	/**
	 * Append author info to the posts REST response so we can append Coauthors, if they exist.
	 */
	public static function add_newspack_extra_info() {
		// Add author info source.
		register_rest_field(
			'post',
			'newspack_author_info',
			[
				'get_callback' => [ __CLASS__, 'newspack_get_author_info' ],
				'schema'       => [
					'context' => [
						'edit',
					],
					'type'    => 'array',
				],
			]
		);

		// Add custom byline info.
		register_rest_field(
			'post',
			'newspack_custom_byline',
			[
				'get_callback' => [ __CLASS__, 'newspack_get_custom_byline' ],
				'schema'       => [
					'context' => [
						'edit',
					],
					'type'    => 'string',
				],
			]
		);

		// Add sponsor info.
		if ( function_exists( '\Newspack_Sponsors\get_all_sponsors' ) ) {
			register_rest_field(
				'post',
				'newspack_sponsors_info',
				[
					'get_callback' => [ __CLASS__, 'newspack_get_sponsors_info' ],
					'schema'       => [
						'context' => [
							'edit',
						],
						'type'    => 'array',
					],
				]
			);
		}

		// Add featured media thumbnail URLs.
		register_rest_field(
			'post',
			'featured_media_info',
			[
				'get_callback' => [ __CLASS__, 'newspack_get_featured_media_info' ],
				'schema'       => [
					'context' => [
						'edit',
					],
					'type'    => 'array',
				],
			]
		);
	}

	/**
	 * After fetching posts, reset the excerpt length.
	 *
	 * @param array $posts Array of posts.
	 *
	 * @return array Unmodified array of posts.
	 */
	public static function maybe_reset_excerpt_length( $posts ) {
		if ( self::$newspack_newsletters_excerpt_length_filter ) {
			self::remove_excerpt_length_filter();
		}

		return $posts;
	}

	/**
	 * Filter for excerpt length.
	 *
	 * @param int $excerpt_length Excerpt length to set.
	 */
	public static function filter_excerpt_length( $excerpt_length ) {
		// If showing excerpt, filter the length using the block attribute.
		if ( is_int( $excerpt_length ) ) {
			self::$newspack_newsletters_excerpt_length_filter = add_filter(
				'excerpt_length',
				function () use ( $excerpt_length ) {
					return $excerpt_length;
				},
				999
			);
			add_filter( 'wc_memberships_trimmed_restricted_excerpt', [ __CLASS__, 'remove_wc_memberships_excerpt_limit' ], 999 );
		}
	}

	/**
	 * Remove excerpt length filter after newsletters post loop.
	 */
	public static function remove_excerpt_length_filter() {
		remove_filter(
			'excerpt_length',
			self::$newspack_newsletters_excerpt_length_filter,
			999
		);
		remove_filter( 'wc_memberships_trimmed_restricted_excerpt', [ __CLASS__, 'remove_wc_memberships_excerpt_limit' ] );
	}

	/**
	 * Function to override WooCommerce Membership's Excerpt Length filter.
	 *
	 * @return string Current post's original excerpt.
	 */
	public static function remove_wc_memberships_excerpt_limit() {
		$excerpt = get_the_excerpt( get_the_id() );
		return $excerpt;
	}

	/**
	 * Get current user test emails
	 *
	 * @return array List of user defined emails.
	 */
	public static function get_current_user_test_emails() {
		$user_id = get_current_user_id();
		$emails  = get_user_meta( $user_id, 'newspack_nl_test_emails', true );
		if ( ! is_array( $emails ) ) {
			$user_info = get_userdata( $user_id );
			return array( $user_info->user_email );
		}
		return $emails;
	}

	/**
	 * Append author data to the REST /posts response, so we can include Coauthors, link and display names.
	 *
	 * @param object $post Post object for the post being returned.
	 * @return object Formatted data for all authors associated with the post.
	 */
	public static function newspack_get_author_info( $post ) {
		$author_data = [];


		if ( function_exists( 'get_coauthors' ) ) {
			$authors = get_coauthors();

			foreach ( $authors as $author ) {
				$author_link = null;
				if ( function_exists( 'coauthors_posts_links' ) ) {
					$author_link = get_author_posts_url( $author->ID, $author->user_nicename );
				}
				$author_data[] = [
					/* Get the author name */
					'display_name' => esc_html( $author->display_name ),
					/* Get the author ID */
					'id'           => $author->ID,
					/* Get the author Link */
					'author_link'  => $author_link,
				];
			}
		} else {
			$author_data[] = [
				/* Get the author name */
				'display_name' => get_the_author_meta( 'display_name', $post['author'] ),
				/* Get the author ID */
				'id'           => $post['author'],
				/* Get the author Link */
				'author_link'  => get_author_posts_url( $post['author'] ),
			];
		}

		/* Return the author data */
		return $author_data;
	}

	/**
	 * Get custom byline for the REST /posts response.
	 *
	 * @param object $post Post object for the post being returned.
	 * @return string|null Formatted custom byline HTML or null if not active.
	 */
	public static function newspack_get_custom_byline( $post ) {
		if ( ! class_exists( 'Newspack\Bylines' ) ) {
			return null;
		}

		return \Newspack\Bylines::get_custom_byline_html( $post['id'] );
	}

	/**
	 * Append sponsor data to the REST /posts response.
	 *
	 * @param object $post Post object for the post being returned.
	 * @return array Formatted data for all sponsors associated with the post.
	 */
	public static function newspack_get_sponsors_info( $post ) {
		return \Newspack_Sponsors\get_all_sponsors( $post['id'], null, 'post' );
	}

	/**
	 * Get featured media info for the REST /posts response.
	 *
	 * @param object $post Post object for the post being returned.
	 * @return object Formatted data for the featured media associated with the post.
	 */
	public static function newspack_get_featured_media_info( $post ) {
		$featured_media_info = [];
		$large_url = get_the_post_thumbnail_url( $post['id'], 'large' );
		$medium_url = get_the_post_thumbnail_url( $post['id'], 'medium' );
		if ( $large_url ) {
			$featured_media_info['large_url'] = $large_url;
		}
		if ( $medium_url ) {
			$featured_media_info['medium_url'] = $medium_url;
		}
		return $featured_media_info;
	}
}
Newspack_Newsletters_Editor::instance();
