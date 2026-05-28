<?php
/**
 * Newspack Popups set up
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main Newspack Popups Class.
 */
final class Newspack_Popups {

	const NEWSPACK_POPUPS_CPT                   = 'newspack_popups_cpt';
	const NEWSPACK_POPUPS_TAXONOMY              = 'newspack_popups_taxonomy';
	const NEWSPACK_POPUPS_ACTIVE_CAMPAIGN_GROUP = 'newspack_popups_active_campaign_group';
	const NEWSPACK_POPUP_PREVIEW_QUERY_PARAM    = 'pid';
	const NEWSPACK_POPUP_PRESET_QUERY_PARAM     = 'preset';
	const NEWSPACK_POPUPS_TAXONOMY_STATUS       = 'newspack_popups_taxonomy_status';

	const PREVIEW_QUERY_KEYS = [
		'background_color'               => 'n_bc',
		'hide_border'                    => 'n_hb',
		'large_border'                   => 'n_lb',
		'no_padding'                     => 'n_np',
		'frequency'                      => 'n_fr',
		'frequency_max'                  => 'n_fm',
		'frequency_start'                => 'n_fs',
		'frequency_between'              => 'n_fb',
		'frequency_reset'                => 'n_ft',
		'close_button_background_color'  => 'n_cb',
		'enable_close_button_background' => 'n_cbg',
		'overlay_color'                  => 'n_oc',
		'overlay_opacity'                => 'n_oo',
		'overlay_size'                   => 'n_os',
		'no_overlay_background'          => 'n_bg',
		'placement'                      => 'n_pl',
		'trigger_type'                   => 'n_tt',
		'trigger_delay'                  => 'n_td',
		'trigger_scroll_progress'        => 'n_ts',
		'trigger_blocks_count'           => 'n_tb',
		'archive_insertion_posts_count'  => 'n_ac',
		'archive_insertion_is_repeating' => 'n_ar',
		'utm_suppression'                => 'n_ut',
		'additional_classes'             => 'n_acl',
	];

	/**
	 * The single instance of the class.
	 *
	 * @var Newspack_Popups
	 */
	protected static $instance = null;

	/**
	 * Whether the segmentation features are enabled
	 *
	 * @var bool
	 */
	public static $segmentation_enabled;

	/**
	 * Main Newspack Ads Instance.
	 * Ensures only one instance of Newspack Ads is loaded or can be loaded.
	 *
	 * @return Newspack Ads - Main instance.
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

		// Segmentation requires the main Newspack plugin.
		self::$segmentation_enabled = class_exists( '\Newspack\Reader_Data' );

		add_action( 'cli_init', [ __CLASS__, 'register_cli_commands' ] );

		add_action( 'init', [ __CLASS__, 'register_cpt' ] );
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'init', [ __CLASS__, 'register_taxonomy' ] );
		add_action( 'init', [ __CLASS__, 'disable_prompts_for_protected_pages' ] );
		add_action( 'init', [ __CLASS__, 'maybe_create_temp_reader_session' ] );
		add_action( 'enqueue_block_assets', [ __CLASS__, 'enqueue_block_assets' ] );
		add_filter( 'display_post_states', [ __CLASS__, 'display_post_states' ], 10, 2 );
		add_action( 'save_post_' . self::NEWSPACK_POPUPS_CPT, [ __CLASS__, 'popup_default_fields' ], 10, 3 );
		add_action( 'transition_post_status', [ __CLASS__, 'prevent_default_category_on_publish' ], 10, 3 );
		add_action( 'transition_post_status', [ __CLASS__, 'store_activation_dates' ], 10, 3 );
		add_action( 'pre_delete_term', [ __CLASS__, 'prevent_default_category_on_term_delete' ], 10, 2 );
		add_filter( 'show_admin_bar', [ __CLASS__, 'show_admin_bar' ], 10, 2 ); // phpcs:ignore WordPressVIPMinimum.UserExperience.AdminBarRemoval.RemovalDetected
		add_filter( 'newspack_blocks_should_deduplicate', [ __CLASS__, 'newspack_blocks_should_deduplicate' ], 10, 2 );

		include_once __DIR__ . '/class-newspack-popups-logger.php';
		include_once __DIR__ . '/class-newspack-popups-model.php';
		include_once __DIR__ . '/class-newspack-segments-migration.php';
		include_once __DIR__ . '/class-newspack-segments-model.php';
		include_once __DIR__ . '/class-newspack-popups-presets.php';
		include_once __DIR__ . '/class-newspack-popups-inserter.php';
		include_once __DIR__ . '/class-newspack-popups-api.php';
		include_once __DIR__ . '/class-newspack-popups-settings.php';
		include_once __DIR__ . '/class-newspack-popups-segmentation.php';
		include_once __DIR__ . '/class-newspack-popups-custom-placements.php';
		include_once __DIR__ . '/class-newspack-popups-view-as.php';
		include_once __DIR__ . '/class-newspack-popups-data-api.php';
		include_once __DIR__ . '/class-newspack-popups-criteria.php';
		include_once __DIR__ . '/class-newspack-popups-expiry.php';
		include_once __DIR__ . '/merge-tags/class-merge-tag.php';
		include_once __DIR__ . '/merge-tags/class-merge-tags.php';
	}

	/**
	 * Handle deduplication of "Homepage Posts" block from Newspack Blocks.
	 *
	 * @param boolean $deduplicate Whether to deduplicate.
	 * @param array   $attributes  Block attributes.
	 *
	 * @return boolean
	 */
	public static function newspack_blocks_should_deduplicate( $deduplicate, $attributes ) {
		$current_popup = Newspack_Popups_Model::get_current_popup();
		if ( $current_popup && Newspack_Popups_Model::is_overlay( $current_popup ) ) {
			$deduplicate = false;
		}
		return $deduplicate;
	}

	/**
	 * Register CLI commands.
	 *
	 * @return void
	 */
	public static function register_cli_commands() {
		WP_CLI::add_command( 'newspack-popups export', 'Newspack\Campaigns\CLI\Export' );
		WP_CLI::add_command( 'newspack-popups import', 'Newspack\Campaigns\CLI\Import' );
		WP_CLI::add_command( 'newspack-popups prune-data', 'Newspack\Campaigns\CLI\Prune_Data' );
		WP_CLI::add_command( 'newspack-popups delete-all', 'Newspack\Campaigns\CLI\Delete_All' );
	}

	/**
	 * Register the custom post type.
	 */
	public static function register_cpt() {
		$labels = [
			'name'               => _x( 'Prompts', 'post type general name', 'newspack-popups' ),
			'singular_name'      => _x( 'Prompt', 'post type singular name', 'newspack-popups' ),
			'menu_name'          => _x( 'Prompts', 'admin menu', 'newspack-popups' ),
			'name_admin_bar'     => _x( 'Prompt', 'add new on admin bar', 'newspack-popups' ),
			'add_new'            => _x( 'Add New', 'popup', 'newspack-popups' ),
			'add_new_item'       => __( 'Add New Prompt', 'newspack-popups' ),
			'new_item'           => __( 'New Prompt', 'newspack-popups' ),
			'edit_item'          => __( 'Edit Prompt', 'newspack-popups' ),
			'view_item'          => __( 'View Prompt', 'newspack-popups' ),
			'all_items'          => __( 'All Prompts', 'newspack-popups' ),
			'search_items'       => __( 'Search Prompts', 'newspack-popups' ),
			'parent_item_colon'  => __( 'Parent Prompts:', 'newspack-popups' ),
			'not_found'          => __( 'No Prompts found.', 'newspack-popups' ),
			'not_found_in_trash' => __( 'No Prompts found in Trash.', 'newspack-popups' ),
		];

		$cpt_args = [
			'labels'       => $labels,
			'public'       => false,
			'show_in_menu' => false,
			'show_ui'      => true,
			'show_in_rest' => true,
			'supports'     => [ 'editor', 'title', 'custom-fields', 'thumbnail', 'revisions' ],
			'taxonomies'   => [ 'category', 'post_tag' ],
			'menu_icon'    => 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgdmlld0JveD0iMCAwIDI0IDI0IiByb2xlPSJpbWciIGFyaWEtaGlkZGVuPSJ0cnVlIiBmb2N1c2FibGU9ImZhbHNlIj48cGF0aCBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik02Ljg2MyAxMy42NDRMNSAxMy4yNWgtLjVhLjUuNSAwIDAxLS41LS41di0zYS41LjUgMCAwMS41LS41SDVMMTggNi41aDJWMTZoLTJsLTMuODU0LS44MTUuMDI2LjAwOGEzLjc1IDMuNzUgMCAwMS03LjMxLTEuNTQ5em0xLjQ3Ny4zMTNhMi4yNTEgMi4yNTEgMCAwMDQuMzU2LjkyMWwtNC4zNTYtLjkyMXptLTIuODQtMy4yOEwxOC4xNTcgOGguMzQzdjYuNWgtLjM0M0w1LjUgMTEuODIzdi0xLjE0NnoiIGNsaXAtcnVsZT0iZXZlbm9kZCIgZmlsbD0id2hpdGUiPjwvcGF0aD48L3N2Zz4K',
		];
		\register_post_type( self::NEWSPACK_POPUPS_CPT, $cpt_args );
	}

	/**
	 * Register custom fields.
	 */
	public static function register_meta() {
		\register_meta(
			'post',
			'trigger_type',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => true,
				'type'           => 'string',
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'trigger_scroll_progress',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => true,
				'type'           => 'integer',
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			// Not really a "trigger", since this meta applies only to inline prompts. Keeping the "trigger"-based naming for consistency.
			'trigger_blocks_count',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => true,
				'type'           => 'integer',
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'archive_insertion_posts_count',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => true,
				'type'           => 'integer',
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'archive_insertion_is_repeating',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => true,
				'type'           => 'boolean',
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'trigger_delay',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => true,
				'type'           => 'integer',
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'frequency',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => true,
				'type'           => 'string',
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'frequency_max',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => true,
				'type'           => 'integer',
				'default'        => 0,
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'frequency_start',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => true,
				'type'           => 'integer',
				'default'        => 0,
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'frequency_between',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => true,
				'type'           => 'integer',
				'default'        => 0,
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'frequency_reset',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => true,
				'type'           => 'string',
				'default'        => 'month',
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'placement',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => true,
				'type'           => 'string',
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'utm_suppression',
			[
				'object_subtype'    => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'      => true,
				'type'              => 'string',
				'single'            => true,
				'auth_callback'     => '__return_true',
				'sanitize_callback' => function( $input ) {
					if ( empty( $input ) ) {
						return '';
					}
					return preg_replace( '~[^-\w0-9_\s]+~', '', $input );
				},
			]
		);

		\register_meta(
			'post',
			'background_color',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => true,
				'type'           => 'string',
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'close_button_background_color',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => true,
				'type'           => 'string',
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'enable_close_button_background',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => true,
				'type'           => 'boolean',
				'default'        => false,
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'overlay_color',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => true,
				'type'           => 'string',
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'overlay_opacity',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => true,
				'type'           => 'integer',
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'overlay_size',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => true,
				'type'           => 'string',
				'default'        => 'medium',
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'no_overlay_background',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => true,
				'type'           => 'boolean',
				'default'        => false,
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'hide_border',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => true,
				'type'           => 'boolean',
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'large_border',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => true,
				'type'           => 'boolean',
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'no_padding',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => true,
				'type'           => 'boolean',
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'duplicate_of',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => true,
				'type'           => 'integer',
				'default'        => 0,
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'post_types',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => [
					'schema' => [
						'items' => [
							'type' => 'string',
						],
					],
				],
				'type'           => 'array',
				'default'        => Newspack_Popups_Model::get_default_popup_post_types(),
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'archive_page_types',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => [
					'schema' => [
						'items' => [
							'type' => 'string',
						],
					],
				],
				'type'           => 'array',
				// Must match the wp_parse_args fallback in Newspack_Popups_Model::create_popup_options().
				'default'        => Newspack_Popups_Model::get_archive_page_types_meta_default(),
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'additional_classes',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => true,
				'type'           => 'string',
				'default'        => '',
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'excluded_categories',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => [
					'schema' => [
						'items' => [
							'type' => 'integer',
						],
					],
				],
				'type'           => 'array',
				'default'        => [],
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'excluded_tags',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => [
					'schema' => [
						'items' => [
							'type' => 'integer',
						],
					],
				],
				'type'           => 'array',
				'default'        => [],
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'expiration_date',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => true,
				'type'           => 'string',
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'activation_date',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => true,
				'type'           => 'string',
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		\register_meta(
			'post',
			'deactivation_date',
			[
				'object_subtype' => self::NEWSPACK_POPUPS_CPT,
				'show_in_rest'   => true,
				'type'           => 'string',
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);

		// Meta field for all post types.
		\register_meta(
			'post',
			'newspack_popups_has_disabled_popups',
			[
				'show_in_rest'  => true,
				'type'          => 'boolean',
				'single'        => true,
				'auth_callback' => '__return_true',
			]
		);
	}

	/**
	 * Register Campaigns taxonomy.
	 */
	public static function register_taxonomy() {
		$taxonomy_args = [
			'labels'        => [
				'name'          => __( 'Campaigns', 'newspack-popups' ),
				'singular_name' => __( 'Campaign', 'newspack-popups' ),
				'add_new_item'  => __( 'Add Campaign', 'newspack-popups' ),
			],
			'hierarchical'  => true,
			'public'        => false,
			'rewrite'       => false, // phpcs:ignore Squiz.PHP.CommentedOutCode.Found [ 'hierarchical' => true, 'slug' => $prefix . '/category' ]
			'show_in_menu'  => false,
			'show_in_rest'  => true,
			'show_tagcloud' => false,
			'show_ui'       => true,
		];

		register_taxonomy( self::NEWSPACK_POPUPS_TAXONOMY, [ self::NEWSPACK_POPUPS_CPT ], $taxonomy_args );

		// Better safe than sorry: https://developer.wordpress.org/reference/functions/register_taxonomy/#more-information.
		register_taxonomy_for_object_type( self::NEWSPACK_POPUPS_TAXONOMY, [ self::NEWSPACK_POPUPS_CPT ] );
	}

	/**
	 * Get preview post permalink.
	 */
	public static function preview_post_permalink() {
		$query        = new WP_Query(
			[
				'posts_per_page' => 1,
				'post_status'    => 'publish',
				'has_password'   => false,
				'orderby'        => 'post_date',
				'order'          => 'DESC',
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					[
						'key'     => 'newspack_popups_has_disabled_popups',
						'compare' => 'NOT EXISTS',
					],
					[
						'key'   => 'newspack_popups_has_disabled_popups',
						'value' => '',
					],
				],
			]
		);
		$recent_posts = $query->get_posts();
		return $recent_posts && count( $recent_posts ) > 0 ? get_the_permalink( $recent_posts[0] ) : '';
	}

	/**
	 * Get preview archive permalink. Used to preview prompts in archive pages in the popup wizard.
	 *
	 * @return string
	 */
	public static function preview_archive_permalink() {
		$categories = array_values( get_categories() );

		return count( $categories ) > 0 ? get_category_link( $categories[0] ) : '';
	}

	/**
	 * Load up common JS/CSS for the editor.
	 */
	public static function enqueue_block_assets() {
		if ( ! is_admin() ) {
			return;
		}
		$screen = get_current_screen();

		// Block assets for Custom Placement and Prompt blocks.
		\wp_enqueue_script(
			'newspack-popups-blocks',
			plugins_url( '../dist/blocks.js', __FILE__ ),
			[],
			filemtime( dirname( NEWSPACK_POPUPS_PLUGIN_FILE ) . '/dist/blocks.js' ),
			true
		);

		\wp_localize_script(
			'newspack-popups-blocks',
			'newspack_popups_blocks_data',
			[
				'custom_placements' => Newspack_Popups_Custom_Placements::get_custom_placements(),
				'endpoint'          => '/newspack-popups/v1/prompts',
				'post_type'         => self::NEWSPACK_POPUPS_CPT,
				'is_prompt'         => self::NEWSPACK_POPUPS_CPT == get_post_type(),
			]
		);

		\wp_register_style(
			'newspack-popups-blocks',
			plugins_url( '../dist/blocks.css', __FILE__ ),
			[],
			filemtime( dirname( NEWSPACK_POPUPS_PLUGIN_FILE ) . '/dist/blocks.css' )
		);
		wp_style_add_data( 'newspack-popups-blocks', 'rtl', 'replace' );
		wp_enqueue_style( 'newspack-popups-blocks' );

		// Don't enqueue Prompt editor files if we don't have a valid post type or ID (e.g. on the Widget Blocks screen).
		if ( empty( $screen->post_type ) || empty( get_the_ID() ) ) {
			return;
		}

		if ( self::NEWSPACK_POPUPS_CPT !== $screen->post_type ) {
			// It's not a popup CPT.

			$supported_post_types = Newspack_Popups_Model::get_default_popup_post_types();
			if ( in_array( $screen->post_type, $supported_post_types, true ) ) {
				// But it's a supported post type.
				\wp_enqueue_script(
					'newspack-popups',
					plugins_url( '../dist/documentSettings.js', __FILE__ ),
					[],
					filemtime( dirname( NEWSPACK_POPUPS_PLUGIN_FILE ) . '/dist/documentSettings.js' ),
					true
				);
			}

			return;
		}

		\wp_enqueue_script(
			'newspack-popups',
			plugins_url( '../dist/editor.js', __FILE__ ),
			[ 'wp-components' ],
			filemtime( dirname( NEWSPACK_POPUPS_PLUGIN_FILE ) . '/dist/editor.js' ),
			true
		);

		\wp_localize_script(
			'newspack-popups',
			'newspack_popups_data',
			[
				'frontend_url'                 => get_site_url(),
				'preview_post'                 => self::preview_post_permalink(),
				'preview_archive'              => self::preview_archive_permalink(),
				'custom_placements'            => Newspack_Popups_Custom_Placements::get_custom_placements(),
				'overlay_placements'           => Newspack_Popups_Model::get_overlay_placements(),
				'popup_size_options'           => Newspack_Popups_Model::get_popup_size_options(),
				'available_archive_page_types' => Newspack_Popups_Model::get_available_archive_page_types(),
				'taxonomy'                     => self::NEWSPACK_POPUPS_TAXONOMY,
				'is_prompt'                    => self::NEWSPACK_POPUPS_CPT == get_post_type(),
				'segments_taxonomy'            => Newspack_Segments_Model::TAX_SLUG,
				'segments_admin_url'           => admin_url( 'admin.php?page=newspack-audience-campaigns#/segments' ),
				'available_post_types'         => array_values(
					get_post_types(
						[
							'public'       => true,
							'show_in_rest' => true,
							'_builtin'     => false,
						],
						'objects'
					)
				),
				'preview_query_keys'           => self::PREVIEW_QUERY_KEYS,
				'segmentation_enabled'         => self::$segmentation_enabled,
			]
		);
		\wp_enqueue_style(
			'newspack-popups-editor',
			plugins_url( '../dist/editor.css', __FILE__ ),
			null,
			filemtime( dirname( NEWSPACK_POPUPS_PLUGIN_FILE ) . '/dist/editor.css' )
		);
	}

	/**
	 * Display popup states by the pop-ups.
	 *
	 * @param array   $post_states An array of post display states.
	 * @param WP_Post $post        The current post object.
	 */
	public static function display_post_states( $post_states, $post ) {
		if ( self::NEWSPACK_POPUPS_CPT !== $post->post_type ) {
			return $post_states;
		}
		$post_status_object = get_post_status_object( $post->post_status );
		$is_inline          = get_post_meta( $post->ID, 'placement', true ) == 'inline';

		if ( $is_inline ) {
			$post_states[ $post_status_object->name ] = __( 'Inline', 'newspack-popups' );
		}

		return $post_states;
	}

	/**
	 * Should admin bar be shown.
	 *
	 * @param bool $show Whether to show admin bar.
	 * @return boolean Whether admin bar should be shown.
	 */
	public static function show_admin_bar( $show ) {
		if ( $show ) {
			return ! self::is_preview_request();
		}
		return $show;
	}

	/**
	 * Is it a preview request – a single popup preview or using "view as" feature.
	 *
	 * @return boolean Whether it's a preview request.
	 */
	public static function is_preview_request() {
		$is_customizer_preview = is_customize_preview();
		// Used by the Newspack Plugin's Campaigns Wizard.
		$is_view_as_preview = false != Newspack_Popups_View_As::viewing_as_spec();
		return ! empty( self::previewed_popup_id() ) || ! empty( self::preset_popup_id() ) || $is_view_as_preview || $is_customizer_preview;
	}

	/**
	 * Get previewed popup id from the URL.
	 *
	 * @return number|null Popup id, if found in the URL
	 */
	public static function previewed_popup_id() {
		// Not using filter_input since it's not playing well with phpunit.
		if ( isset( $_GET[ self::NEWSPACK_POPUP_PREVIEW_QUERY_PARAM ] ) && $_GET[ self::NEWSPACK_POPUP_PREVIEW_QUERY_PARAM ] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return sanitize_text_field( $_GET[ self::NEWSPACK_POPUP_PREVIEW_QUERY_PARAM ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return null;
	}

	/**
	 * Get preset popup slug from the URL.
	 *
	 * @return string|null Popup slug, if found in the URL
	 */
	public static function preset_popup_id() {
		// Not using filter_input since it's not playing well with phpunit.
		if ( isset( $_GET[ self::NEWSPACK_POPUP_PRESET_QUERY_PARAM ] ) && $_GET[ self::NEWSPACK_POPUP_PRESET_QUERY_PARAM ] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return sanitize_text_field( $_GET[ self::NEWSPACK_POPUP_PRESET_QUERY_PARAM ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return null;
	}

	/**
	 * Set default fields when Pop-up is created.
	 *
	 * @param int     $post_id ID of post being saved.
	 * @param WP_POST $post The post being saved.
	 * @param bool    $update True if this is an update, false if a newly created post.
	 */
	public static function popup_default_fields( $post_id, $post, $update ) {
		// Set meta only if this is a newly created post.
		if ( $update ) {
			return;
		}
		$type      = isset( $_GET['placement'] ) ? sanitize_text_field( $_GET['placement'] ) : null; //phpcs:ignore
		$segment   = isset( $_GET['segment'] ) ? sanitize_text_field( $_GET['segment'] ) : ''; //phpcs:ignore
		$group     = isset( $_GET['group'] ) ? absint( $_GET['group'] ) : null; //phpcs:ignore
		$frequency    = 'daily';
		$overlay_size = 'medium';

		switch ( $type ) {
			case 'overlay-center':
				$placement = 'center';
				break;
			case 'overlay-top':
				$placement    = 'top';
				$overlay_size = 'full-width';
				break;
			case 'overlay-bottom':
				$placement    = 'bottom';
				$overlay_size = 'full-width';
				break;
			case 'archives':
				$placement = 'archives';
				$frequency = 'always';
				break;
			case 'above-header':
				$placement = 'above_header';
				$frequency = 'always';
				break;
			case 'custom':
				$placement = 'custom1';
				$frequency = 'always';
				break;
			case 'manual':
				$placement = 'manual';
				$frequency = 'always';
				break;
			default:
				$placement = 'inline';
				$frequency = 'always';
				break;
		}

		switch ( $type ) {
			case 'overlay-center':
			case 'overlay-top':
			case 'overlay-bottom':
				$trigger_type = 'time';
				break;
			case 'above-header':
			case 'custom':
			default:
				$trigger_type = 'scroll';
				break;
		}

		update_post_meta( $post_id, 'background_color', '#FFFFFF' );
		update_post_meta( $post_id, 'hide_border', false );
		update_post_meta( $post_id, 'large_border', false );
		update_post_meta( $post_id, 'no_padding', false );
		update_post_meta( $post_id, 'frequency', $frequency );
		update_post_meta( $post_id, 'close_button_background_color', '#00000000' );
		update_post_meta( $post_id, 'enable_close_button_background', false );
		update_post_meta( $post_id, 'overlay_color', '#000000' );
		update_post_meta( $post_id, 'overlay_opacity', 30 );
		update_post_meta( $post_id, 'overlay_size', $overlay_size );
		update_post_meta( $post_id, 'no_overlay_background', false );
		update_post_meta( $post_id, 'placement', $placement );
		update_post_meta( $post_id, 'trigger_type', $trigger_type );
		update_post_meta( $post_id, 'trigger_delay', 3 );
		update_post_meta( $post_id, 'trigger_scroll_progress', 30 );
		update_post_meta( $post_id, 'trigger_blocks_count', 3 );
		update_post_meta( $post_id, 'archive_insertion_posts_count', 0 );
		update_post_meta( $post_id, 'archive_insertion_is_repeating', false );
		update_post_meta( $post_id, 'archive_page_types', Newspack_Popups_Model::get_default_popup_archive_page_types() );
		update_post_meta( $post_id, 'utm_suppression', '' );

		if ( $group ) {
			wp_set_post_terms( $post_id, [ $group ], self::NEWSPACK_POPUPS_TAXONOMY );
		}

		if ( $segment && ! wp_get_post_terms( $post_id, Newspack_Segments_Model::TAX_SLUG ) ) {
			wp_set_post_terms( $post_id, [ $segment ], Newspack_Segments_Model::TAX_SLUG );
		}
	}

	/**
	 * Is the user an admin or editor user?
	 * If so, prompts will be shown to these users while logged in, but analytics
	 * will not be fired for them.
	 */
	public static function is_user_admin() {
		/**
		 * Filter to allow other plugins to decide which capability should be checked
		 * to determine whether a user's activity should be tracked via Google Analytics.
		 *
		 * @param string $capability Capability to check. Default: edit_others_pages.
		 * @return string Filtered capability string.
		 */
		$capability = apply_filters( 'newspack_popups_admin_user_capability', 'edit_others_pages' );
		return is_user_logged_in() && current_user_can( $capability );
	}

	/**
	 * Is the post related to the user account.
	 *
	 * @param WP_Post $post The prompt post object.
	 */
	public static function is_account_related_post( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		return has_shortcode( $post->post_content, 'woocommerce_my_account' );
	}

	/**
	 * Create campaign.
	 *
	 * @param string $name New campaign name.
	 */
	public static function create_campaign( $name ) {
		$term = wp_insert_term( $name, self::NEWSPACK_POPUPS_TAXONOMY );
		if ( is_wp_error( $term ) ) {
			$term = get_term_by( 'name', $name, self::NEWSPACK_POPUPS_TAXONOMY );
		}
		$term = (object) $term;
		return $term->term_id;
	}

	/**
	 * Delete campaign.
	 *
	 * @param int $id Campaign ID.
	 */
	public static function delete_campaign( $id ) {
		wp_delete_term( $id, self::NEWSPACK_POPUPS_TAXONOMY );
	}

	/**
	 * Duplicate campaign.
	 *
	 * @param int    $id Campaign ID.
	 * @param string $name New campaign name.
	 */
	public static function duplicate_campaign( $id, $name ) {
		$term = wp_insert_term( $name, self::NEWSPACK_POPUPS_TAXONOMY );
		if ( is_wp_error( $term ) ) {
			$term = get_term_by( 'name', $name, self::NEWSPACK_POPUPS_TAXONOMY );
		}
		$term = (object) $term;
		$args = [
			'post_type'      => self::NEWSPACK_POPUPS_CPT,
			'posts_per_page' => 100,
			'fields'         => 'ids',
			'post_status'    => [ 'publish', 'pending', 'draft', 'future' ],
			'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy' => self::NEWSPACK_POPUPS_TAXONOMY,
					'field'    => 'term_id',
					'terms'    => [ $id ],
				],
			],
		];

		$query = new WP_Query( $args );
		foreach ( $query->posts as $id ) {
			wp_set_post_terms( $id, $term->term_id, self::NEWSPACK_POPUPS_TAXONOMY, true );
		}
		return $term->term_id;
	}

	/**
	 * Rename campaign.
	 *
	 * @param int    $id Campaign ID.
	 * @param string $name New campaign name.
	 */
	public static function rename_campaign( $id, $name ) {
		wp_update_term(
			$id,
			self::NEWSPACK_POPUPS_TAXONOMY,
			[
				'name' => $name,
			]
		);
	}

	/**
	 * Archive campaign.
	 *
	 * @param int  $id Campaign ID.
	 * @param bool $status Whether to archive or unarchive (true = archive, false = unarchive).
	 */
	public static function archive_campaign( $id, $status ) {
		update_term_meta( $id, self::NEWSPACK_POPUPS_TAXONOMY_STATUS, $status ? 'archive' : '' );
	}

	/**
	 * Remove the default category from the given post, if it's the only category applied to that post.
	 *
	 * @param int $post_id ID of the post.
	 */
	private static function remove_default_category( $post_id ) {
		$default_category_id = (int) get_option( 'default_category', 0 );
		if ( empty( $default_category_id ) ) {
			return;
		}

		$post_categories = wp_get_post_categories( $post_id );
		if ( 1 === count( $post_categories ) && reset( $post_categories ) === $default_category_id ) {
			wp_remove_object_terms( $post_id, $default_category_id, 'category' );
		}
	}

	/**
	 * Prevent setting the default category when publishing.
	 *
	 * @param string $new_status New status.
	 * @param string $old_status Old status.
	 * @param bool   $post Post.
	 */
	public static function prevent_default_category_on_publish( $new_status, $old_status, $post ) {
		if ( self::NEWSPACK_POPUPS_CPT === $post->post_type && 'publish' !== $old_status && 'publish' === $new_status ) {
			self::remove_default_category( $post->ID );
		}
	}

	/**
	 * Stores the activation date of the prompt.
	 *
	 * @param string $new_status New status.
	 * @param string $old_status Old status.
	 * @param bool   $post Post.
	 */
	public static function store_activation_dates( $new_status, $old_status, $post ) {
		if ( self::NEWSPACK_POPUPS_CPT !== $post->post_type ) {
			return;
		}
		if ( 'publish' !== $old_status && 'publish' === $new_status ) {
			update_post_meta( $post->ID, 'activation_date', gmdate( 'Y-m-d H:i:s' ) );
		}
		if ( 'publish' === $old_status && 'publish' !== $new_status ) {
			update_post_meta( $post->ID, 'deactivation_date', gmdate( 'Y-m-d H:i:s' ) );
		}
	}

	/**
	 * When a category is deleted, any posts that have only that category assigned
	 * are automatically assigned the site's default category (usually "Uncategorized").
	 * We want to prevent this behavior for prompts, as prompts with the default
	 * category will only appear on posts with that category.
	 *
	 * @param int    $deleted_term ID of the term being deleted.
	 * @param string $taxonomy Name of the taxonomy the term belongs to.
	 *
	 * @return int The number of prompts affected by this callback.
	 */
	public static function prevent_default_category_on_term_delete( $deleted_term, $taxonomy ) {
		// We only care about categories.
		if ( 'category' !== $taxonomy ) {
			return;
		}

		$default_category_id = (int) get_option( 'default_category', 0 );
		if ( empty( $default_category_id ) ) {
			return;
		}

		$prompts_with_deleted_category = get_posts(
			[
				'category__in'     => $deleted_term,
				'category__not_in' => $default_category_id, // We don't want to remove the default category if it was intentionally added.
				'fields'           => 'ids',
				'post_status'      => 'any',
				'post_type'        => self::NEWSPACK_POPUPS_CPT,
				'posts_per_page'   => -1,
			]
		);

		if ( empty( $prompts_with_deleted_category ) ) {
			return;
		}

		// When the default category is assigned to a prompt and it wasn't previously assigned, remove it.
		add_action(
			'set_object_terms',
			// Use an anonymous function that can read the variables above in its closure.
			function( $post_id, $terms, $tt_ids, $taxonomy ) use ( $default_category_id, $prompts_with_deleted_category ) {
				if (
					self::NEWSPACK_POPUPS_CPT === get_post_type( $post_id ) &&
					in_array( $post_id, $prompts_with_deleted_category, true ) &&
					in_array( $default_category_id, $terms, true ) &&
					'category' === $taxonomy
				) {
					self::remove_default_category( $post_id );
				}
			},
			10,
			4
		);
	}

	/**
	 * Retrieve campaigns.
	 *
	 * @return WP_Term[] An array of WP_Term objects.
	 */
	public static function get_groups() {
		$terms = get_terms( // phpcs:ignore WordPress.WP.DeprecatedParameters.Get_termsParam2Found
			self::NEWSPACK_POPUPS_TAXONOMY,
			[
				'hide_empty' => false,
			]
		);
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return [];
		}

		$groups = array_map(
			function( $group ) {
				$group->status = get_term_meta(
					$group->term_id,
					self::NEWSPACK_POPUPS_TAXONOMY_STATUS,
					true
				);
				return $group;
			},
			$terms
		);
		return $groups;
	}

	/**
	 * Generate the duplicated post title base.
	 *
	 * @param string $original_title Original post title.
	 * @param string $parent_title Post to duplicate title.
	 * @return string
	 */
	private static function get_duplicated_post_base_title( $original_title, $parent_title ) {
		/* translators: %s: Duplicate prompt title */
		$original_base_title = sprintf( __( '%s copy', 'newspack-popups' ), $original_title );

		// Prepend ` copy` only if it's not already on the post title.
		return preg_match( "/^$original_base_title\s*\d*$/", $parent_title )
		? $original_base_title
		/* translators: %s: Duplicate prompt title */
		: sprintf( __( '%s copy', 'newspack-popups' ), $parent_title );
	}

	/**
	 * Get a default title for duplicated prompts.
	 *
	 * @param int $original_id The ID of the original prompt.
	 * @param int $parent_id The ID of the prompt being duplicated.
	 * @return string The title for the duplicated prompt.
	 */
	public static function get_duplicate_title( $original_id, $parent_id ) {
		$original_title  = get_the_title( $original_id );
		$duplicate_title = self::get_duplicated_post_base_title( $original_title, get_the_title( $parent_id ) );

		$duplicated_posts = new \WP_Query(
			[
				'post_status' => [ 'publish', 'draft', 'pending', 'future' ],
				'post_type'   => self::NEWSPACK_POPUPS_CPT,
				'meta_key'    => 'duplicate_of', // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'  => $original_id,
			]
		);

		$duplicated_posts_with_same_title = array_filter(
			$duplicated_posts->get_posts(),
			function( $prompt ) use ( $original_title ) {
				/* translators: %s: Duplicate prompt title */
				$original_prompt_title = sprintf( __( '%s copy', 'newspack-popups' ), $original_title );
				// Filter duplicated posts with a duplicate count postponed to the same title.
				return preg_match( "/^$original_prompt_title\s*\d*$/", $prompt->post_title );
			}
		);

		$duplicate_count = count( $duplicated_posts_with_same_title );

		// Append iterator to title if there are already copies.
		if ( 0 < $duplicate_count ) {
			$duplicate_title .= ' ' . strval( $duplicate_count + 1 );
		}

		return $duplicate_title;
	}

	/**
	 * Duplicate a prompt. Duplicates are created with all the same content and options
	 * as the source prompt, but are always set to draft status at first.
	 *
	 * @param int    $id Prompt ID to duplicate.
	 * @param string $title Title to give to the duplicate.
	 * @return int|boolean|WP_Error The copy's post ID, false if the ID to copy isn't a valid prompt, or WP_Error if the operation failed.
	 */
	public static function duplicate_popup( $id, $title = '' ) {
		$old_popup    = get_post( $id );
		$new_popup_id = false;

		if ( is_a( $old_popup, 'WP_Post' ) && self::NEWSPACK_POPUPS_CPT === $old_popup->post_type ) {
			$duplicate_of        = get_post_meta( $id, 'duplicate_of', true );
			$original_id         = 0 < $duplicate_of ? $duplicate_of : $id; // If the post we're duplicating is itself a copy, inherit the 'duplicate_of' value. Otherwise, set the value to the post we're duplicating.
			$original_title_base = self::get_duplicated_post_base_title( get_the_title( $original_id ), $title );
			$new_popup           = [
				'post_type'     => self::NEWSPACK_POPUPS_CPT,
				'post_status'   => 'draft',
				'post_title'    => ! empty( $title ) ? $title : self::get_duplicate_title( $duplicate_of, $id ),
				'post_author'   => $old_popup->post_author,
				'post_content'  => $old_popup->post_content,
				'post_excerpt'  => $old_popup->post_excerpt,
				'post_category' => wp_get_post_categories( $id, [ 'fields' => 'ids' ] ),
				'tags_input'    => wp_get_post_tags( $id, [ 'fields' => 'ids' ] ),
				'meta_input'    => [
					// A campaign is set as the origin of another one, if the later have the same title with the count of occurences suffixed (e.g. my prompt 3).
					'duplicate_of' => empty( $title ) || preg_match( "/^$original_title_base\s*\d*$/", $title )
									? $original_id : 0,
				],
			];

			// Create the copy.
			$new_popup_id = wp_insert_post( $new_popup );

			// Apply campaign taxonomy.
			$old_campaigns = wp_get_post_terms(
				$id,
				self::NEWSPACK_POPUPS_TAXONOMY,
				[ 'fields' => 'ids' ]
			);
			wp_set_post_terms( $new_popup_id, $old_campaigns, self::NEWSPACK_POPUPS_TAXONOMY );

			// Apply segment terms.
			$old_segments = wp_get_post_terms(
				$id,
				Newspack_Segments_Model::TAX_SLUG,
				[ 'fields' => 'ids' ]
			);
			wp_set_post_terms( $new_popup_id, $old_segments, Newspack_Segments_Model::TAX_SLUG );

			// Set prompt options to match old prompt.
			$old_popup_options = Newspack_Popups_Model::get_popup_options( $id );
			foreach ( $old_popup_options as $key => $value ) {
				update_post_meta( $new_popup_id, $key, $value );
			}
		}

		return $new_popup_id;
	}

	/**
	 * Disable prompts by default if the given post ID is a protected page,
	 * e.g. My Account, Donate, Privacy Policy, etc. other than the homepage or blog page.
	 * Protected pages are defined in the \Newspack\Patches class.
	 */
	public static function disable_prompts_for_protected_pages() {
		if ( class_exists( '\Newspack\Patches' ) ) {
			$protected_page_ids = \Newspack\Patches::get_protected_page_ids();
			$front_page_id      = intval( get_option( 'page_on_front', -1 ) );
			$blog_posts_id      = intval( get_option( 'page_for_posts', -1 ) );
			foreach ( $protected_page_ids as $page_id ) {
				if (
					$page_id !== $front_page_id &&
					$page_id !== $blog_posts_id &&
					! in_array( 'newspack_popups_has_disabled_popups', array_keys( get_post_meta( $page_id ) ), true )
				) {
					update_post_meta( $page_id, 'newspack_popups_has_disabled_popups', true );
				}
			}
		}
	}

	/**
	 * If the current session is a preview, ensure that reader data does not persist past the session.
	 */
	public static function maybe_create_temp_reader_session() {
		$is_preview = self::is_preview_request();
		if ( ! $is_preview ) {
			return;
		}

		// For one-off previews, just generate a random number.
		$session_id = \wp_rand( 0, 9999 );

		// If a view_as session, parse the spec for an ID that can be passed between pageviews in the session.
		$view_as_spec = Newspack_Popups_View_As::parse_view_as();
		if ( ! empty( $view_as_spec['session_id'] ) ) {
			$session_id = $view_as_spec['session_id'];
		}

		// Tell the reader store to use sessionStorage instead of localStorage, and tie the reader prefix to the session.
		\add_filter( 'newspack_reader_data_store_is_temp_session', '__return_true' );
		\add_filter(
			'newspack_reader_data_store_prefix',
			function() use ( $session_id ) {
				return sprintf( 'np_temp_session_%d_', $session_id );
			}
		);
	}
}
Newspack_Popups::instance();
