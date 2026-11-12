<?php
/**
 * Newspack setup wizard. Required plugins, introduction, and data collection.
 *
 * @package Newspack
 */

namespace Newspack;

use WP_Error, WP_REST_Request, WP_REST_Response, WP_REST_Server;
defined( 'ABSPATH' ) || exit;

/**
 * Setup Newspack.
 */
class Setup_Wizard extends Wizard {
	const INSTALL_STATE_OPTION = 'newspack_setup_install_state';

	/**
	 * Query arg that renders the front end as the setup wizard's site preview.
	 */
	const DESIGN_PREVIEW_QUERY_ARG = 'newspack_design_preview';

	/**
	 * The slug of this wizard.
	 *
	 * @var string
	 */
	protected $slug = 'newspack-setup-wizard';

	/**
	 * The parent menu item name.
	 *
	 * @var string
	 */
	public $parent_menu = 'newspack-dashboard';

	/**
	 * Make sure Setup is first submenu item (after the dashboard wizard creates the "Newspack" menu).
	 *
	 * @var int.
	 */
	protected $admin_menu_priority = 2;

	/**
	 * The capability required to access this wizard.
	 *
	 * @var string
	 */
	protected $capability = 'manage_options';

	/**
	 * An array of theme mods that are media library IDs.
	 *
	 * @var array
	 */
	protected $media_theme_mods = [ 'newspack_footer_logo', 'custom_logo' ];

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		add_action( 'rest_api_init', [ $this, 'register_api_endpoints' ] );
		if ( ! Newspack::is_setup_complete() ) {
			add_action( 'current_screen', [ $this, 'redirect_to_setup' ] );
			add_action( 'admin_menu', [ $this, 'hide_non_setup_menu_items' ], 1000 );
		}
		add_filter( 'show_admin_bar', [ $this, 'show_admin_bar' ], 10, 2 ); // phpcs:ignore WordPressVIPMinimum.UserExperience.AdminBarRemoval.RemovalDetected
		add_filter( 'newspack_popups_assess_has_disabled_popups', [ __CLASS__, 'disable_prompts_in_design_preview' ] );
		$this->hidden = get_option( NEWSPACK_SETUP_COMPLETE, false );
	}

	/**
	 * Get the name for this wizard.
	 *
	 * @return string The wizard name.
	 */
	public function get_name() {
		return esc_html__( 'Setup', 'newspack' );
	}

	/**
	 * Register the endpoints needed for the wizard screens.
	 */
	public function register_api_endpoints() {
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/initial-check',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_initial_check' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);
		// Update option when setup is complete.
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/complete',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_complete' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/theme',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_retrieve_theme_and_set_defaults' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/theme',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_theme_with_mods' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'theme_mods' => [
						'sanitize_callback' => [ $this, 'sanitize_theme_mods' ],
					],
					'theme'      => [
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/starter-content/init',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_starter_content_init' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/starter-content/post/(?P<id>[\a-z]+)',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_starter_content_post' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/starter-content/theme',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_starter_content_theme' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/starter-content/homepage',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_starter_content_homepage' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'layout_only' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/starter-content/identity',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_starter_content_identity' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/install-state',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_install_state' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'approach' => [
						'type'     => 'string',
						'enum'     => [ 'generated', 'import' ],
						'required' => true,
					],
					'complete' => [
						'type'     => 'boolean',
						'required' => true,
					],
				],
			]
		);
	}

	/**
	 * Perform an initial check before starting setup.
	 *
	 * @return WP_REST_Response containing info.
	 */
	public function api_initial_check() {
		$required_plugins_slugs = [
			'akismet',
			'jetpack',
			'pwa',
			'wordpress-seo',
			'google-site-kit',
			'newspack-blocks',
			'newspack-content-blocks',
			'newspack-newsletters',
			'newspack-theme',
		];

		// Gather information about required plugins.
		$managed_plugins = Plugin_Manager::get_managed_plugins();
		$plugin_info     = [];
		foreach ( $required_plugins_slugs as $slug ) {
			$plugin               = $managed_plugins[ $slug ];
			$plugin['Configured'] = \Newspack\Configuration_Managers::is_configured( $slug );
			$plugin_info[]        = $plugin;
		}

		$homepage_edit_url = null;
		$front_page_id     = (int) get_option( 'page_on_front' );
		if ( 'page' === get_option( 'show_on_front' ) && $front_page_id && get_post( $front_page_id ) ) {
			$homepage_edit_url = get_edit_post_link( $front_page_id, 'raw' );
		}

		return rest_ensure_response(
			[
				'plugins'           => $plugin_info,
				'is_ssl'            => is_ssl() || Starter_Content::is_e2e(),
				'install_state'     => self::get_install_state(),
				'homepage_edit_url' => $homepage_edit_url ? $homepage_edit_url : null,
				'next_steps'        => [
					'reader_revenue' => admin_url( 'admin.php?page=newspack-audience' ),
					'newsletters'    => class_exists( 'Newspack_Newsletters' ) ? admin_url( 'edit.php?post_type=newspack_nl_cpt' ) : null,
					'ads'            => class_exists( 'Newspack_Ads\Core' ) ? admin_url( 'admin.php?page=newspack-ads-display-ads' ) : null,
				],
			]
		);
	}

	/**
	 * Update option when setup is complete.
	 *
	 * @return WP_REST_Response containing info.
	 */
	public function api_complete() {
		update_option( NEWSPACK_SETUP_COMPLETE, true );
		delete_option( Starter_Content::IMPORTED_CURRENCY_OPTION );
		return rest_ensure_response( [ 'complete' => true ] );
	}

	/**
	 * Initialize a starter content generator.
	 *
	 * @param WP_REST_Request $request API request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function api_starter_content_init( $request ) {
		$request_params = $request->get_params();
		if ( empty( $request_params ) || empty( $request_params['type'] ) ) {
			return new WP_Error( 'newspack_setup', __( 'Missing starter content initialization info', 'newspack' ) );
		}

		$starter_content_type = 'import' === $request_params['type'] ? 'import' : 'generated';
		$existing_site_url    = ! empty( $request_params['site'] ) && wp_http_validate_url( $request_params['site'] ) ? esc_url_raw( $request_params['site'] ) : '';

		if ( 'import' === $starter_content_type && ! $existing_site_url ) {
			return new WP_Error(
				'newspack_setup',
				sprintf(
					/* translators: %s - Site URL */
					__( 'Invalid site URL: "%s".', 'newspack' ),
					sanitize_text_field( $request_params['site'] )
				)
			);
		}

		$result = Starter_Content::initialize( $starter_content_type, $existing_site_url );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = [ 'status' => 200 ];
		if ( 'import' === $starter_content_type ) {
			$response['post_count'] = Starter_Content_WordPress::get_post_count();
		}
		return rest_ensure_response( $response );
	}

	/**
	 * Install one starter content post
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response containing info.
	 */
	public function api_starter_content_post( $request ) {
		$id     = $request['id'];
		$status = Starter_Content::create_post( $id );
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		return rest_ensure_response( [ 'status' => $status ] );
	}

	/**
	 * Set up initial theme mods
	 *
	 * @return WP_REST_Response containing info.
	 */
	public function api_starter_content_theme() {
		$status = Starter_Content::initialize_theme();
		return rest_ensure_response( [ 'status' => $status ] );
	}

	/**
	 * Set up Homepage
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error containing info.
	 */
	public function api_starter_content_homepage( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( $request['layout_only'] ) {
			$status = Starter_Content::create_layout_homepage();
			if ( is_wp_error( $status ) ) {
				return $status;
			}
			return rest_ensure_response( [ 'status' => true ] );
		}
		$status = Starter_Content::create_homepage();
		return rest_ensure_response( [ 'status' => $status ] );
	}

	/**
	 * Import the identity of the site being moved.
	 *
	 * @return WP_REST_Response|WP_Error Imported items.
	 */
	public function api_starter_content_identity(): WP_REST_Response|WP_Error {
		$result = Starter_Content::import_identity();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Get current theme & mods.
	 *
	 * @return WP_REST_Response containing info.
	 */
	public function api_retrieve_theme_and_set_defaults() {
		$theme_mods = get_theme_mods();

		$theme_mods['header_color'] = get_theme_mod( 'header_color', 'default' );
		// Force custom header color, since the default depends on the theme.
		if ( 'default' === $theme_mods['header_color'] ) {
			set_theme_mod( 'header_color', 'custom' );
			$theme_mods['header_color'] = get_theme_mod( 'header_color' );
		}

		foreach ( $theme_mods as $key => &$theme_mod ) {
			if ( in_array( $key, $this->media_theme_mods ) ) {
				$attachment = wp_get_attachment_image_src( $theme_mod, 'large' );
				if ( $attachment ) {
					$metadata  = wp_get_attachment_metadata( $theme_mod );
					$theme_mod = [
						'id'     => $theme_mod,
						'url'    => is_array( $attachment ) ? $attachment[0] : null,
						'width'  => $metadata['width'] ?? null,
						'height' => $metadata['height'] ?? null,
					];
				}
			}
		}
		$theme_mods['theme_colors'] = get_theme_mod( 'theme_colors', 'default' );
		if ( function_exists( '\newspack_get_primary_color' ) ) {
			if ( 'default' === $theme_mods['theme_colors'] ) {
				$theme_mods['primary_color_hex']   = \newspack_get_primary_color();
				$theme_mods['secondary_color_hex'] = \newspack_get_secondary_color();
			} else {
				$theme_mods['primary_color_hex']   = get_theme_mod( 'primary_color_hex', \newspack_get_primary_color() );
				$theme_mods['secondary_color_hex'] = get_theme_mod( 'secondary_color_hex', \newspack_get_secondary_color() );
			}
		}

		// Set custom header color to primary, if not set.
		if ( ! isset( $theme_mods['header_color_hex'] ) ) {
			set_theme_mod( 'header_color_hex', $theme_mods['primary_color_hex'] );
			$theme_mods['header_color_hex'] = get_theme_mod( 'header_color_hex' );
		}
		if ( ! isset( $theme_mods['homepage_pattern_index'] ) ) {
			$theme_mods['homepage_pattern_index'] = 0;
		}

		$theme_mods['accent_allcaps'] = get_theme_mod( 'accent_allcaps', true );

		// Footer.
		$theme_mods['footer_color']     = get_theme_mod( 'footer_color', 'default' );
		$theme_mods['footer_logo_size'] = get_theme_mod( 'footer_logo_size', 'medium' );
		$theme_mods['footer_copyright'] = get_theme_mod( 'footer_copyright', false );
		if ( false === $theme_mods['footer_copyright'] ) {
			$theme_mods['footer_copyright'] = get_theme_mod( 'footer_copyright', '' );
		}

		$theme_mods['header_text']            = get_theme_mod( 'header_text', true );
		$theme_mods['header_display_tagline'] = get_theme_mod( 'header_display_tagline', true );

		// Append media credits settings.
		$media_credits_settings = Newspack_Image_Credits::get_settings();
		foreach ( $media_credits_settings as $key => $value ) {
			$theme_mods[ $key ] = $value;

			if ( 'newspack_image_credits_placeholder' === $key && ! empty( $value ) ) {
				$attachment_url = wp_get_attachment_image_url( $value, 'full' );
				if ( $attachment_url ) {
					$theme_mods['newspack_image_credits_placeholder_url'] = $attachment_url;
				}
			}
		}

		// Append PWA display mode option.
		$theme_mods['pwa_display_mode'] = get_option( 'newspack_pwa_display_mode', 'minimal-ui' );

		// Append post content fallback image option.
		$theme_mods['post_content_fallback_image'] = get_option( Default_Image::OPTION_NAME, null );

		/**
		 * Filters the settings returned by the Setup Wizard's theme/settings endpoint.
		 *
		 * Hooked callbacks can add non-theme-mod settings (e.g. feature options
		 * stored as wp_options) to the response array.
		 *
		 * @param array $theme_mods The settings array (mix of theme mods and custom settings).
		 */
		$theme_mods = apply_filters( 'newspack_setup_wizard_settings', $theme_mods );

		return rest_ensure_response(
			[
				'theme'             => Starter_Content::get_theme(),
				'theme_mods'        => $theme_mods,
				'homepage_patterns' => Starter_Content::get_homepage_patterns(),
				'etc'               => [
					'post_count'     => wp_count_posts()->publish,
					'has_pwa_plugin' => defined( 'PWA_PLUGIN_FILE' ),
				],
			]
		);
	}

	/**
	 * Update theme
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response containing info.
	 */
	public function api_update_theme_with_mods( $request ) {
		// Set theme before updating theme mods, since a theme might be setting theme mod defaults.
		$theme = $request['theme'];
		Theme_Manager::install_activate_theme( $theme );
		// If the theme has to be installed, the set_theme_mod calls below will – for some reason – have no effect
		// If there's a switch_theme call here, even though it's already called in Theme_Manager::install_activate_theme,
		// correct mods will be saved.
		if ( ! empty( $theme ) ) {
			switch_theme( $theme );
		}

		// Set homepage pattern.
		if ( isset( $request['theme_mods']['homepage_pattern_index'] ) ) {
			$homepage_pattern_index = (int) $request['theme_mods']['homepage_pattern_index'];
			$homepage_pattern       = Starter_Content::get_homepage_pattern( $homepage_pattern_index );
			if ( false !== $homepage_pattern ) {
				$homepage_id = (int) get_option( 'page_on_front', 0 );
				if ( ! get_option( NEWSPACK_SETUP_COMPLETE ) && Starter_Content::is_setup_homepage( $homepage_id ) ) {
					wp_update_post(
						[
							'ID'           => $homepage_id,
							'post_content' => $homepage_pattern['content'],
						]
					);
				}
			}
		}

		$theme_mods = $request['theme_mods'];
		foreach ( $theme_mods as $key => $value ) {
			/**
			 * Filters whether a setting was already handled by a hooked callback.
			 *
			 * Callbacks should check $key and, if recognized, save the value and return
			 * true to prevent the wizard from calling set_theme_mod() for that key.
			 *
			 * @param bool   $handled Whether the setting has been handled.
			 * @param string $key     The setting key.
			 * @param mixed  $value   The setting value.
			 */
			if ( apply_filters( 'newspack_setup_wizard_update_setting', false, $key, $value ) ) {
				continue;
			}

			// Media credits are actually options, not theme mods.
			if ( 'newspack_image_credits' === substr( $key, 0, 22 ) ) {
				Newspack_Image_Credits::update_setting( $key, $value );
				continue;
			}

			// PWA display mode is an option, not a theme mod.
			if ( 'pwa_display_mode' === $key ) {
				update_option( 'newspack_pwa_display_mode', $value );
				continue;
			}

			// Post content fallback image is an option, not a theme mod.
			if ( 'post_content_fallback_image' === $key ) {
				update_option( Default_Image::OPTION_NAME, $value );
				continue;
			}

			// All-posts updates: featured image and post template.
			if ( substr_compare( $key, '_all_posts', -strlen( '_all_posts' ) ) === 0 ) {
				if ( 'none' === $value ) {
					continue;
				}
				switch ( $key ) {
					case 'featured_image_all_posts':
						self::update_meta_key_in_batches( 'newspack_featured_image_position', $value );
						break;
					case 'post_template_all_posts':
						self::update_meta_key_in_batches( '_wp_page_template', $value );
						break;
				}
				continue;
			}

			if ( ! empty( $value['id'] ) && in_array( $key, $this->media_theme_mods ) ) {
				$value = $value['id'];
			}
			set_theme_mod( $key, $value );
		}
		return self::api_retrieve_theme_and_set_defaults();
	}

	/**
	 * Change a meta value on a batch of posts.
	 *
	 * @param string $meta_key The meta key to update.
	 * @param string $value The value to update.
	 * @param int    $page The page of posts to update.
	 */
	private static function update_meta_key_in_batches( $meta_key, $value, $page = 0 ) {
		$args            = [
			'posts_per_page' => 100,
			'post_type'      => 'post',
			'fields'         => 'ids',
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				[
					'key'     => $meta_key,
					'value'   => $value,
					'compare' => '!=',
				],
				[
					'key'     => $meta_key,
					'compare' => 'NOT EXISTS',
				],
			],
		];
		$results         = new \WP_Query( $args );
		$number_of_pages = $results->max_num_pages;

		Logger::log(
			sprintf( 'Updating %s to "%s" on %s posts (batch %d/%d).', $meta_key, $value, count( $results->posts ), $page + 1, $number_of_pages + $page )
		);
		foreach ( $results->posts as $post ) {
			update_post_meta( $post, $meta_key, $value );
		}
		if ( 1 < $number_of_pages ) {
			self::update_meta_key_in_batches( $meta_key, $value, $page + 1 );
		}
	}

	/**
	 * Get the stored install state.
	 *
	 * A site that finished setup before the install state was stored counts as installed.
	 *
	 * @return array{approach: string|null, complete: bool}
	 */
	public static function get_install_state(): array {
		$state = get_option( self::INSTALL_STATE_OPTION, false );
		if ( false === $state ) {
			return [
				'approach' => null,
				'complete' => (bool) get_option( NEWSPACK_SETUP_COMPLETE, false ),
			];
		}
		$state = is_array( $state ) ? $state : [];
		return [
			'approach' => isset( $state['approach'] ) && in_array( $state['approach'], [ 'generated', 'import' ], true ) ? $state['approach'] : null,
			'complete' => ! empty( $state['complete'] ),
		];
	}

	/**
	 * Store the install state.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response containing the stored state.
	 */
	public function api_update_install_state( WP_REST_Request $request ): WP_REST_Response {
		update_option(
			self::INSTALL_STATE_OPTION,
			[
				'approach' => $request['approach'],
				'complete' => (bool) $request['complete'],
			]
		);
		return rest_ensure_response( self::get_install_state() );
	}

	/**
	 * Enqueue Subscriptions Wizard scripts and styles.
	 */
	public function enqueue_scripts_and_styles() {
		parent::enqueue_scripts_and_styles();
		if ( filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) !== $this->slug ) {
			return;
		}
		wp_enqueue_script(
			'newspack-setup-wizard',
			Newspack::plugin_url() . '/dist/setup.js',
			$this->get_script_dependencies(),
			Newspack::asset_version( 'setup' ),
			true
		);
		wp_register_style(
			'newspack-setup-wizard',
			Newspack::plugin_url() . '/dist/setup.css',
			$this->get_style_dependencies(),
			Newspack::asset_version( 'setup' )
		);
		wp_style_add_data( 'newspack-setup-wizard', 'rtl', 'replace' );
		wp_enqueue_style( 'newspack-setup-wizard' );
	}

	/**
	 * Hide all menu items besides setup if setup is incomplete.
	 */
	public function hide_non_setup_menu_items() {
		global $submenu;
		if ( ! current_user_can( $this->capability ) ) {
			return;
		}
		foreach ( $submenu['newspack-dashboard'] as $key => $value ) {
			if ( 'newspack-setup-wizard' !== $value[2] ) {
				unset( $submenu['newspack-dashboard'][ $key ] );
			}
		}
	}

	/**
	 * If initial setup is incomplete, redirect to Setup Wizard.
	 */
	public function redirect_to_setup() {
		$screen = get_current_screen();
		if ( $screen && 'toplevel_page_newspack-dashboard' === $screen->id ) {
			$setup_url = Wizards::get_url( 'setup' );
			wp_safe_redirect( esc_url( $setup_url ) );
			exit;
		}
	}

	/**
	 * Should admin bar be shown.
	 *
	 * @param bool $show Whether to show admin bar.
	 * @return boolean Whether admin bar should be shown.
	 */
	public static function show_admin_bar( $show ) {
		if ( $show && self::is_design_preview() ) {
			return false;
		}
		return $show;
	}

	/**
	 * Whether this request renders the front end as the setup wizard's site preview.
	 *
	 * Only someone who can run the setup gets the preview, so a visitor adding the
	 * query arg sees the page as usual.
	 *
	 * @return bool
	 */
	public static function is_design_preview(): bool {
		return isset( $_GET[ self::DESIGN_PREVIEW_QUERY_ARG ] ) && current_user_can( 'manage_options' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Keep Campaigns prompts out of the site preview.
	 *
	 * @param mixed $disabled Whether prompts are disabled for this request.
	 * @return mixed True in the preview, otherwise the value as filtered so far.
	 */
	public static function disable_prompts_in_design_preview( mixed $disabled ): mixed {
		return self::is_design_preview() ? true : $disabled;
	}

	/**
	 * Sanitize theme mods.
	 *
	 * @param array $theme_mods An array of theme mods.
	 */
	public function sanitize_theme_mods( $theme_mods ) {
		return $theme_mods;
	}
}
