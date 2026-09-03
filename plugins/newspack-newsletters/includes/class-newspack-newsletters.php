<?php
/**
 * Newspack Newsletter Author
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main Newspack Newsletters Class.
 */
final class Newspack_Newsletters {

	const NEWSPACK_NEWSLETTERS_CPT          = 'newspack_nl_cpt';
	const EMAIL_HTML_META                   = 'newspack_email_html';
	const NEWSPACK_NEWSLETTERS_PALETTE_META = 'newspack_newsletters_color_palette';
	const PUBLIC_POST_ID_META               = 'newspack_nl_public_post_id';
	const API_NAMESPACE                     = 'newspack-newsletters/v1';

	/**
	 * Send-config meta keys the ESP send path reads. Single source of truth for
	 * the fields that must be persisted before the send fires (NPPM-2935/2929).
	 * Keep in lockstep with the send-config get_post_meta reads in the provider
	 * send/sync path (e.g. Active_Campaign::create_campaign / sync).
	 */
	const SEND_CONFIG_META_KEYS = [ 'send_list_id', 'send_sublist_id', 'senderName', 'senderEmail' ];

	/**
	 * Supported fonts.
	 *
	 * @var array
	 */
	public static $supported_fonts = [
		'Arial, Helvetica, sans-serif',
		'Tahoma, sans-serif',
		'Trebuchet MS, sans-serif',
		'Verdana, sans-serif',
		'Georgia, serif',
		'Palatino, serif',
		'Times New Roman, serif',
		'Courier, monospace',
	];

	/**
	 * The single instance of the class.
	 *
	 * @var Newspack_Newsletters
	 */
	protected static $instance = null;

	/**
	 * Instance of the service provider class.
	 *
	 * @var Newspack_Newsletters_Service_Provider
	 */
	protected static $provider = null;

	/**
	 * Main Newspack Newsletter Author Instance.
	 * Ensures only one instance of Newspack Author Instance is loaded or can be loaded.
	 *
	 * @return Newspack Author Instance - Main instance.
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
		add_action( 'init', [ __CLASS__, 'memoize_service_provider' ] );
		add_action( 'init', [ __CLASS__, 'register_cpt' ] );
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'init', [ __CLASS__, 'register_editor_only_meta' ] );
		add_action( 'init', [ __CLASS__, 'register_blocks' ] );
		add_action( 'init', [ __CLASS__, 'load_textdomain' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'rest_api_init' ] );
		add_action( 'admin_menu', [ __CLASS__, 'remove_admin_menu_items' ], 99 );
		add_action( 'default_title', [ __CLASS__, 'default_title' ], 10, 2 );
		add_action( 'wp_head', [ __CLASS__, 'public_newsletter_custom_style' ], 10, 2 );
		add_filter( 'display_post_states', [ __CLASS__, 'display_post_states' ], 10, 2 );
		add_filter( 'manage_' . self::NEWSPACK_NEWSLETTERS_CPT . '_posts_columns', [ __CLASS__, 'add_public_page_column' ] );
		add_filter( 'manage_' . self::NEWSPACK_NEWSLETTERS_CPT . '_posts_columns', [ __CLASS__, 'remove_stats_column' ], 99 );
		add_action( 'manage_' . self::NEWSPACK_NEWSLETTERS_CPT . '_posts_custom_column', [ __CLASS__, 'public_page_column_content' ], 10, 2 );
		add_filter( 'post_row_actions', [ __CLASS__, 'display_view_or_preview_link_in_admin' ] );
		add_filter( 'jetpack_relatedposts_filter_options', [ __CLASS__, 'disable_jetpack_related_posts' ] );
		add_action( 'save_post_' . self::NEWSPACK_NEWSLETTERS_CPT, [ __CLASS__, 'save' ], 10, 3 );
		add_filter( 'rest_pre_insert_' . self::NEWSPACK_NEWSLETTERS_CPT, [ __CLASS__, 'persist_send_config_before_send' ], 10, 2 );
		add_filter( 'update_post_metadata', [ __CLASS__, 'guard_public_status_write' ], 10, 4 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'branding_scripts' ] );
		add_filter( 'newspack_theme_featured_image_post_types', [ __CLASS__, 'support_featured_image_options' ] );
		add_filter( 'gform_force_hooks_js_output', [ __CLASS__, 'suppress_gravityforms_js_on_newsletters' ] );
		add_filter( 'render_block', [ __CLASS__, 'remove_visibility_hidden_block' ], 10, 2 );
		add_action( 'pre_get_posts', [ __CLASS__, 'display_newsletters_in_archives' ] );
		add_action( 'the_post', [ __CLASS__, 'fix_public_status' ] );
	}

	/**
	 * Store the service provider instance in a static property.
	 */
	public static function memoize_service_provider() {
		$service_provider = self::service_provider();
		$is_esp_manual    = 'manual' === $service_provider;

		// 'newspack_mailchimp_api_key' is a newer option introduced to manage MC API key accross Newspack plugins.
		// Keeping the old option for backwards compatibility.
		if ( ! $is_esp_manual && ! $service_provider && get_option( 'newspack_mailchimp_api_key', get_option( 'newspack_newsletters_mailchimp_api_key' ) ) ) {
			// Legacy – Mailchimp provider set before multi-provider handling was set up.
			self::set_service_provider( 'mailchimp' );
			$service_provider = 'mailchimp';
		}
		self::$provider = self::get_service_provider_instance( $service_provider );

		$needs_nag = is_admin() && ! self::is_service_provider_configured() && ! get_option( 'newspack_newsletters_activation_nag_viewed', false );
		if ( $needs_nag ) {
			add_action( 'admin_notices', [ __CLASS__, 'activation_nag' ] );
			add_action( 'admin_enqueue_scripts', [ __CLASS__, 'activation_nag_dismissal_script' ] );
			add_action( 'wp_ajax_newspack_newsletters_activation_nag_dismissal', [ __CLASS__, 'activation_nag_dismissal_ajax' ] );
		}
	}

	/**
	 * Get the registered providers.
	 *
	 * @return array
	 */
	public static function get_registered_providers() {

		$providers = [
			'mailchimp'        => [
				'name'  => 'Mailchimp',
				'class' => 'Newspack_Newsletters_Mailchimp',
			],
			'constant_contact' => [
				'name'  => 'Constant Contact',
				'class' => 'Newspack_Newsletters_Constant_Contact',
			],
			'active_campaign'  => [
				'name'  => 'Active Campaign',
				'class' => 'Newspack_Newsletters_Active_Campaign',
			],
		];

		/**
		 * Filter the registered providers.
		 *
		 * To register a new provider, create a class that extends Newspack_Newsletters_Service_Provider
		 * and add it to the $providers array. Include or require it on the 'init' action hook.
		 *
		 * In order to register a new provider, create a new class that extends Newspack_Newsletters_Service_Provider
		 * and add it to the $providers array.
		 *
		 * Do not directly load the class file in your plugin/theme, instead, inform the class name and the file path
		 * to the filter.
		 *
		 * @param array $providers The registered providers. The keys are the provider slugs and the values are arrays with the following structure: {
		 *     @type string $name The provider name.
		 *     @type string $class The provider class name.
		 *     @type string $class_file The provider class file path.
		 * }
		 */
		$providers = apply_filters( 'newspack_newsletters_registered_providers', $providers );

		foreach ( $providers as $provider_slug => $provider ) {
			if ( ! class_exists( $provider['class'] ) && isset( $provider['class_file'] ) && file_exists( $provider['class_file'] ) ) {
				require_once $provider['class_file'];
			}
		}

		return $providers;
	}

	/**
	 * Get the provider class for a given provider slug.
	 *
	 * @param string $provider_slug The provider slug.
	 * @return string|null The provider class or null if not found.
	 */
	public static function get_provider_class( $provider_slug ) {
		$providers = self::get_registered_providers();
		if ( isset( $providers[ $provider_slug ] ) ) {
			return $providers[ $provider_slug ]['class'];
		}
		return null;
	}

	/**
	 * In preparation for deprecating support for Campaign Monitor, locks support behind an environment flag.
	 *
	 * @return array
	 */
	public static function get_supported_providers() {
		$supported_providers = array_keys( self::get_registered_providers() );

		// Add support for manual/other.
		$supported_providers[] = 'manual';

		return $supported_providers;
	}

	/**
	 * Should we show a warning about the coming deprecation of Campaign Monitor?
	 *
	 * @return bool
	 */
	public static function should_deprecate_campaign_monitor() {
		return 'campaign_monitor' === self::service_provider();
	}

	/**
	 * Set service provider.
	 *
	 * @param string $service_provider Service provider slug.
	 */
	public static function set_service_provider( $service_provider ) {
		update_option( 'newspack_newsletters_service_provider', $service_provider );
		self::$provider = self::get_service_provider_instance( $service_provider );
		// get_lists_config() is provider-scoped, so a provider switch must clear its
		// memo or the previous provider's config sticks for the rest of the request.
		if ( class_exists( 'Newspack_Newsletters_Subscription' ) ) {
			Newspack_Newsletters_Subscription::reset_lists_config_cache();
		}
	}

	/**
	 * Gets the Service provider instance
	 *
	 * @param string $provider_slug The provider slug.
	 * @return ?Newspack_Newsletters_Service_Provider
	 */
	public static function get_service_provider_instance( $provider_slug ) {
		if ( empty( self::get_provider_class( $provider_slug ) ) ) {
			return null;
		}
		return self::get_provider_class( $provider_slug )::instance();
	}

	/**
	 * Get the current service provider instance.
	 */
	public static function get_service_provider() {
		return self::$provider;
	}

	/**
	 * Test the active provider's API connection.
	 *
	 * @return true|WP_Error True if the connection is successful, WP_Error otherwise.
	 */
	public static function test_connection() {
		$provider = self::get_service_provider();
		if ( ! $provider ) {
			return new \WP_Error( 'newspack_newsletters_no_provider', __( 'No newsletter service provider configured.', 'newspack-newsletters' ) );
		}
		return $provider->test_connection();
	}

	/**
	 * Register custom fields for use in the editor only.
	 * These have to be registered so the updates are handles correctly.
	 */
	public static function register_editor_only_meta() {
		$default_register_meta_args = [
			'show_in_rest' => [
				'schema' => [
					'context' => [ 'edit' ],
				],
			],
			'type'         => 'string',
		];
		$fields = [
			[
				'name'               => 'stringifiedCampaignDefaults',
				'register_meta_args' => $default_register_meta_args,
			],
			[
				'name'               => 'newsletter_send_errors',
				'register_meta_args' => [
					'show_in_rest' => [
						'schema' => [
							'context' => [ 'edit' ],
							'type'    => 'array',
							'items'   => [
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => [
									'timestamp' => [
										'name' => 'timestamp',
										'type' => 'integer',
									],
									'message'   => [
										'name' => 'message',
										'type' => 'string',
									],
								],
							],
						],
					],
					'type'         => 'object',
				],
			],
		];
		foreach ( $fields as $field ) {
			\register_meta(
				'post',
				$field['name'],
				array_merge(
					$field['register_meta_args'],
					[
						'object_subtype' => self::NEWSPACK_NEWSLETTERS_CPT,
						'single'         => true,
						'auth_callback'  => '__return_true',
					]
				)
			);
		}
	}

	/**
	 * Register custom fields.
	 */
	public static function register_meta() {
		\register_meta(
			'post',
			'campaign_name',
			[
				'object_subtype' => self::NEWSPACK_NEWSLETTERS_CPT,
				'show_in_rest'   => [
					'schema' => [
						'context' => [ 'edit' ],
					],
				],
				'type'           => 'string',
				'single'         => true,
				'auth_callback'  => '__return_true',
				'default'        => '',
			]
		);
		\register_meta(
			'post',
			'template_id',
			[
				'object_subtype' => self::NEWSPACK_NEWSLETTERS_CPT,
				'show_in_rest'   => [
					'schema' => [
						'context' => [ 'edit' ],
					],
				],
				'type'           => 'integer',
				'single'         => true,
				'auth_callback'  => '__return_true',
				'default'        => -1,
			]
		);
		// The four send-config keys below (SEND_CONFIG_META_KEYS) are also
		// committed early by persist_send_config_before_send() on rest_pre_insert
		// so the ESP send reads fresh values. That early write goes through
		// update_post_meta() — so a registered sanitize_callback is still applied
		// — but it bypasses the REST schema validation and the per-key
		// 'edit_post_meta' capability check the normal meta route runs. It is
		// therefore only safe while these keys stay permissive strings
		// (auth_callback __return_true, no restrictive schema/sanitize). If that
		// changes, mirror it in persist_send_config_before_send().
		\register_meta(
			'post',
			'send_list_id',
			[
				'object_subtype' => self::NEWSPACK_NEWSLETTERS_CPT,
				'show_in_rest'   => [
					'schema' => [
						'context' => [ 'edit' ],
					],
				],
				'type'           => 'string',
				'single'         => true,
				'auth_callback'  => '__return_true',
				'default'        => '',
			]
		);
		\register_meta(
			'post',
			'send_sublist_id',
			[
				'object_subtype' => self::NEWSPACK_NEWSLETTERS_CPT,
				'show_in_rest'   => [
					'schema' => [
						'context' => [ 'edit' ],
					],
				],
				'type'           => 'string',
				'single'         => true,
				'auth_callback'  => '__return_true',
				'default'        => '',
			]
		);
		\register_meta(
			'post',
			'senderName',
			[
				'object_subtype' => self::NEWSPACK_NEWSLETTERS_CPT,
				'show_in_rest'   => [
					'schema' => [
						'context' => [ 'edit' ],
					],
				],
				'type'           => 'string',
				'single'         => true,
				'auth_callback'  => '__return_true',
				'default'        => '',
			]
		);
		\register_meta(
			'post',
			'senderEmail',
			[
				'object_subtype' => self::NEWSPACK_NEWSLETTERS_CPT,
				'show_in_rest'   => [
					'schema' => [
						'context' => [ 'edit' ],
					],
				],
				'type'           => 'string',
				'single'         => true,
				'auth_callback'  => '__return_true',
				'default'        => '',
			]
		);
		\register_meta(
			'post',
			'newsletter_sent',
			[
				'object_subtype' => self::NEWSPACK_NEWSLETTERS_CPT,
				'show_in_rest'   => [
					'schema' => [
						'context' => [ 'edit' ],
					],
				],
				'type'           => 'integer',
				'single'         => true,
				'auth_callback'  => '__return_true',
				'default'        => 0,
			]
		);
		\register_meta(
			'post',
			'font_header',
			[
				'object_subtype' => self::NEWSPACK_NEWSLETTERS_CPT,
				'show_in_rest'   => [
					'schema' => [
						'context' => [ 'edit' ],
					],
				],
				'type'           => 'string',
				'single'         => true,
				'auth_callback'  => '__return_true',
				'default'        => '',
			]
		);
		\register_meta(
			'post',
			'font_body',
			[
				'object_subtype' => self::NEWSPACK_NEWSLETTERS_CPT,
				'show_in_rest'   => [
					'schema' => [
						'context' => [ 'edit' ],
					],
				],
				'type'           => 'string',
				'single'         => true,
				'auth_callback'  => '__return_true',
				'default'        => '',
			]
		);
		\register_meta(
			'post',
			'background_color',
			[
				'object_subtype' => self::NEWSPACK_NEWSLETTERS_CPT,
				'show_in_rest'   => [
					'schema' => [
						'context' => [ 'edit' ],
					],
				],
				'type'           => 'string',
				'single'         => true,
				'auth_callback'  => '__return_true',
				'default'        => '',
			]
		);
		\register_meta(
			'post',
			'text_color',
			[
				'object_subtype' => self::NEWSPACK_NEWSLETTERS_CPT,
				'show_in_rest'   => [
					'schema' => [
						'context' => [ 'edit' ],
					],
				],
				'type'           => 'string',
				'single'         => true,
				'auth_callback'  => '__return_true',
				'default'        => '',
			]
		);
		\register_meta(
			'post',
			'preview_text',
			[
				'object_subtype' => self::NEWSPACK_NEWSLETTERS_CPT,
				'show_in_rest'   => [
					'schema' => [
						'context' => [ 'edit' ],
					],
				],
				'type'           => 'string',
				'single'         => true,
				'auth_callback'  => '__return_true',
				'default'        => '',
			]
		);
		\register_meta(
			'post',
			'is_public',
			[
				'object_subtype' => self::NEWSPACK_NEWSLETTERS_CPT,
				'show_in_rest'   => [
					'schema' => [
						'context' => [ 'edit' ],
					],
				],
				'type'           => 'boolean',
				'single'         => true,
				'auth_callback'  => '__return_true',
				'default'        => false,
			]
		);
		\register_meta(
			'post',
			'custom_css',
			[
				'object_subtype' => self::NEWSPACK_NEWSLETTERS_CPT,
				'show_in_rest'   => [
					'schema' => [
						'context' => [ 'edit' ],
					],
				],
				'type'           => 'string',
				'single'         => true,
				'default'        => '',
				'auth_callback'  => '__return_true',
			]
		);
	}

	/**
	 * Set post meta on post creation/save.
	 *
	 * @param string  $post_id Numeric ID of the campaign.
	 * @param WP_Post $post The complete post object.
	 * @param boolean $update Whether this is an existing post being updated or not.
	 */
	public static function save( $post_id, $post, $update ) {
		if ( ! $update ) {
			update_post_meta( $post_id, 'template_id', -1 ); // Set default layout. This can be removed once WP 5.5 adoption is sufficient.
			update_post_meta( $post_id, self::PUBLIC_POST_ID_META, wp_generate_password( 20, false, false ) ); // Generate a token that can be used to identify this post publicly.
		}
	}

	/**
	 * Persist send-config to post meta before the post is updated.
	 *
	 * The ESP send is triggered from `pre_post_update`, which fires inside
	 * `wp_update_post()` — BEFORE the REST controller writes the request's
	 * post meta. Without this, the send reads stale send-config and emails the
	 * previously-stored list/segment/sender (NPPM-2935) or fails with an empty
	 * sender (NPPM-2929). `rest_pre_insert_{cpt}` fires in
	 * prepare_item_for_database, before `wp_update_post()`, so committing the
	 * request's send-config here guarantees the send reads current values.
	 *
	 * `rest_pre_insert` runs only after the route's edit_post permission check,
	 * and these meta keys carry no custom sanitize/auth callbacks, so no REST
	 * guarantee is bypassed; `wp_slash` mirrors the normal meta write so the
	 * value the send reads matches what is finally stored. Non-scalar values are
	 * skipped (the REST schema rejects them moments later anyway). The early write
	 * is committed during prepare and is intentionally not rolled back if the
	 * enclosing post update later fails — acceptable because the send only fires on
	 * a successful status transition within that same update.
	 *
	 * @param stdClass        $prepared_post Post object about to be inserted/updated.
	 * @param WP_REST_Request $request       The REST request.
	 * @return stdClass The unchanged prepared post.
	 */
	public static function persist_send_config_before_send( $prepared_post, $request ) {
		// Only existing posts have a send to protect; a new auto-draft has no ID and no send.
		if ( empty( $prepared_post->ID ) ) {
			return $prepared_post;
		}
		$meta = $request['meta'];
		if ( ! is_array( $meta ) ) {
			return $prepared_post;
		}
		foreach ( self::SEND_CONFIG_META_KEYS as $key ) {
			if ( ! array_key_exists( $key, $meta ) ) {
				continue;
			}
			$value = $meta[ $key ];
			// Send-config keys are scalar strings; skip a malformed non-scalar value.
			// It would emit a cast warning below and persist a bogus value the send
			// reads before REST schema validation rejects the request. null is allowed
			// (it clears the field, matching the normal meta path's effect on read).
			if ( null !== $value && ! is_scalar( $value ) ) {
				continue;
			}
			// Skip a redundant write when unchanged (defense-in-depth; keeps unchanged saves byte-identical).
			if ( (string) $value === (string) get_post_meta( $prepared_post->ID, $key, true ) ) {
				continue;
			}
			update_post_meta( $prepared_post->ID, $key, wp_slash( $value ) );
		}
		return $prepared_post;
	}

	/**
	 * Register the custom post type.
	 */
	public static function register_cpt() {
		$public_slug = get_option( 'newspack_newsletters_public_posts_slug', 'newsletter' );

		// Prevent empty slug value.
		if ( empty( $public_slug ) ) {
			$public_slug = 'newsletter';
		}

		$labels = [
			'name'                     => _x( 'Newsletters', 'post type general name', 'newspack-newsletters' ),
			'singular_name'            => _x( 'Newsletter', 'post type singular name', 'newspack-newsletters' ),
			'menu_name'                => _x( 'Newsletters', 'admin menu', 'newspack-newsletters' ),
			'name_admin_bar'           => _x( 'Newsletter', 'add new on admin bar', 'newspack-newsletters' ),
			'add_new'                  => _x( 'Add New', 'newsletter', 'newspack-newsletters' ),
			'add_new_item'             => __( 'Add Newsletter', 'newspack-newsletters' ),
			'new_item'                 => __( 'New Newsletter', 'newspack-newsletters' ),
			'edit_item'                => __( 'Edit Newsletter', 'newspack-newsletters' ),
			'view_item'                => __( 'View Newsletter', 'newspack-newsletters' ),
			'view_items'               => __( 'View Newsletters', 'newspack-newsletters' ),
			'all_items'                => __( 'All Newsletters', 'newspack-newsletters' ),
			'search_items'             => __( 'Search Newsletters', 'newspack-newsletters' ),
			'parent_item_colon'        => __( 'Parent Newsletters:', 'newspack-newsletters' ),
			'not_found'                => __( 'No newsletters found.', 'newspack-newsletters' ),
			'not_found_in_trash'       => __( 'No newsletters found in Trash.', 'newspack-newsletters' ),
			'archives'                 => __( 'Newsletter Archives', 'newspack-newsletters' ),
			'attributes'               => __( 'Newsletter Attributes', 'newspack-newsletters' ),
			'insert_into_item'         => __( 'Insert into newsletter', 'newspack-newsletters' ),
			'uploaded_to_this_item'    => __( 'Uploaded to this newsletter', 'newspack-newsletters' ),
			'filter_items_list'        => __( 'Filter newsletters list', 'newspack-newsletters' ),
			'items_list_navigation'    => __( 'Newsletters list navigation', 'newspack-newsletters' ),
			'items_list'               => __( 'Newsletters list', 'newspack-newsletters' ),
			'item_published'           => __( 'Newsletter sent.', 'newspack-newsletters' ),
			'item_published_privately' => __( 'Newsletter published privately.', 'newspack-newsletters' ),
			'item_reverted_to_draft'   => __( 'Newsletter reverted to draft.', 'newspack-newsletters' ),
			'item_scheduled'           => __( 'Newsletter scheduled.', 'newspack-newsletters' ),
			'item_updated'             => __( 'Newsletter updated.', 'newspack-newsletters' ),
			'item_link'                => __( 'Newsletter Link', 'newspack-newsletters' ),
			'item_link_description'    => __( 'A link to a newsletter.', 'newspack-newsletters' ),
		];

		$supports = [
			'author',
			'editor' => [ 'notes' => true ],
			'title',
			'custom-fields',
			'newspack_blocks',
			'revisions',
			'thumbnail',
			'excerpt',
		];

		if ( get_option( 'newspack_newsletters_support_comments' ) ) {
			$supports[] = 'comments';
		}

		$cpt_args = [
			'has_archive'      => $public_slug,
			'labels'           => $labels,
			'public'           => true,
			'public_queryable' => true,
			'query_var'        => true,
			'rewrite'          => [ 'slug' => $public_slug ],
			'show_ui'          => true,
			'show_in_rest'     => true,
			'supports'         => $supports,
			'taxonomies'       => [ 'category', 'post_tag' ],
			'menu_icon'        => 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" fill="none"><path fill-rule="evenodd" clip-rule="evenodd" d="M3 7c0-1.1.9-2 2-2h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Zm2-.5h14c.3 0 .5.2.5.5v1L12 13.5 4.5 7.9V7c0-.3.2-.5.5-.5Zm-.5 3.3V17c0 .3.2.5.5.5h14c.3 0 .5-.2.5-.5V9.8L12 15.4 4.5 9.8Z"></path></svg>' ),
		];
		\register_post_type( self::NEWSPACK_NEWSLETTERS_CPT, $cpt_args );
	}

	/**
	 * Drop redundant Categories / Tags submenus from the Newsletters
	 * CPT — they're shared with Posts and surfacing twice is clutter.
	 */
	public static function remove_admin_menu_items() {
		if ( ! get_post_type_object( self::NEWSPACK_NEWSLETTERS_CPT ) ) {
			return;
		}

		$cpt_parent = 'edit.php?post_type=' . self::NEWSPACK_NEWSLETTERS_CPT;
		remove_submenu_page( $cpt_parent, 'edit-tags.php?taxonomy=category&amp;post_type=' . self::NEWSPACK_NEWSLETTERS_CPT );
		remove_submenu_page( $cpt_parent, 'edit-tags.php?taxonomy=post_tag&amp;post_type=' . self::NEWSPACK_NEWSLETTERS_CPT );
	}

	/**
	 * Register blocks server-side for front-end rendering.
	 */
	public static function register_blocks() {
		$block_definition = json_decode(
			file_get_contents( __DIR__ . '/../src/editor/blocks/posts-inserter/block.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			true
		);
		register_block_type(
			$block_definition['name'],
			[
				'render_callback' => [ __CLASS__, 'render_posts_inserter_block' ],
				'attributes'      => $block_definition['attributes'],
				'supports'        => $block_definition['supports'],
			]
		);
		register_block_type_from_metadata(
			__DIR__ . '/../src/editor/blocks/share/block.json',
			[
				'render_callback' => [ __CLASS__, 'render_share_block' ],
			]
		);
		// Register the ad block so the WC email renderer can locate its
		// render_email_callback. Block_Renderer_Registry sets that callback via
		// the `block_type_metadata_settings` filter (priority 11), which fires
		// here during register_block_type_from_metadata() — after the registry's
		// init() has already hooked it at plugin-load time (before `init`). Guard
		// against a context that re-runs registration (multibranded/network) so we
		// don't trip a `_doing_it_wrong` notice for a double registration.
		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( 'newspack-newsletters/ad' ) ) {
			register_block_type_from_metadata(
				__DIR__ . '/../src/editor/blocks/ad/block.json'
			);
		}
	}

	/**
	 * Set text domain.
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( 'newspack-newsletters', false, NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/languages/' );
	}

	/**
	 * Server-side render callback for Posts Inserter block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string HTML of block content to render.
	 */
	public static function render_posts_inserter_block( $attributes ) {
		$markup = '';

		if ( empty( $attributes['innerBlocksToInsert'] ) || ! is_array( $attributes['innerBlocksToInsert'] ) ) {
			return $markup;
		}

		foreach ( $attributes['innerBlocksToInsert'] as $inner_block ) {
			$markup .= $inner_block['innerHTML'];
		}

		return wp_kses_post( $markup );
	}

	/**
	 * Server-side render callback for Share block.
	 * It does not make sense to render anything when the email
	 * is viewed as a public post.
	 */
	public static function render_share_block() {
		return '';
	}

	/**
	 * Filter post states in admin posts list.
	 *
	 * @param array   $post_states An array of post display states.
	 * @param WP_Post $post        The current post object.
	 * @return array The filtered $post_states array.
	 */
	public static function display_post_states( $post_states, $post ) {
		if ( self::NEWSPACK_NEWSLETTERS_CPT !== $post->post_type ) {
			return $post_states;
		}

		$post_status = get_post_status_object( $post->post_status );
		$sent        = self::is_newsletter_sent( $post->ID );
		if ( $sent ) {
			$time_diff = time() - $sent;

			// Show relative date if sent within the past 24 hours.
			if ( $time_diff < 86400 ) {
				$sent_from_now = human_time_diff( $sent, time() );
				/* translators: Relative time stamp of sent/published date */
				$post_states[ $post_status->name ] = sprintf( __( 'Sent %1$s ago', 'newspack-newsletters' ), $sent_from_now );
			} else {
				/* translators:  Absolute time stamp of sent/published date */
				$post_states[ $post_status->name ] = sprintf( __( 'Sent %1$s', 'newspack-newsletters' ), ( new DateTime( '@' . $sent ) )->format( get_option( 'date_format' ) ) );
			}
		}

		return $post_states;
	}

	/**
	 * Add "Public page" admin column
	 *
	 * @param array $columns Newsletters columns.
	 *
	 * @return array
	 */
	public static function add_public_page_column( $columns ) {
		return array_merge( $columns, [ 'public_page' => __( 'Public page', 'newspack-newsletters' ) ] );
	}

	/**
	 * Remove the Jetpack Stats column from the Newsletters admin list table.
	 *
	 * @param array $columns Newsletters columns.
	 *
	 * @return array
	 */
	public static function remove_stats_column( $columns ) {
		unset( $columns['stats'] );
		return $columns;
	}

	/**
	 * Add "Public page" admin column content
	 * Displays wether the newsletter post has a public page or not
	 *
	 * @param string $column_name Column name.
	 * @param int    $post_id     Post ID.
	 */
	public static function public_page_column_content( $column_name, $post_id ) {
		if ( 'public_page' === $column_name ) {
			$is_public = get_post_meta( $post_id, 'is_public', true );
			?>
			<span class="inline_data is_public" data-is_public="<?php echo esc_attr( $is_public ); ?>">
				<?php echo empty( $is_public ) ? esc_html__( 'No', 'newspack-newsletters' ) : esc_html__( 'Yes', 'newspack-newsletters' ); ?>
			</span>
			<?php
		}
	}

	/**
	 * Make "View" links say "Preview" if the newsletter is not marked as public.
	 *
	 * @param array $actions Array of action links to be shown in admin posts list.
	 * @return array Filtered array of action links.
	 */
	public static function display_view_or_preview_link_in_admin( $actions ) {
		if ( 'publish' !== get_post_status() || self::NEWSPACK_NEWSLETTERS_CPT !== get_post_type() ) {
			return $actions;
		}

		$is_public = get_post_meta( get_the_ID(), 'is_public', true );

		if ( empty( $is_public ) && isset( $actions['view'] ) ) {
			$actions['view'] = '<a href="' . esc_url( get_the_permalink() ) . '" rel="bookmark" aria-label="View ' . esc_attr( get_the_title() ) . '">Preview</a>';
		}

		return $actions;
	}

	/**
	 * Disable Jetpack Related Posts on Newsletter posts.
	 *
	 * @param array $options Options array for Jetpack Related Posts.
	 * @return array Filtered options array.
	 */
	public static function disable_jetpack_related_posts( $options ) {
		if (
			self::NEWSPACK_NEWSLETTERS_CPT === get_post_type() &&
			! empty( get_option( 'newspack_newsletters_disable_related_posts' ) )
		) {
			$options['enabled'] = false;
		}

		return $options;
	}

	/**
	 * Add newspack_popups_is_sitewide_default to Popup object.
	 */
	public static function rest_api_init() {
		\register_rest_route(
			self::API_NAMESPACE,
			'layouts',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'api_get_layouts' ],
				'permission_callback' => [ __CLASS__, 'api_edit_posts_permissions_check' ],
				'args'                => [
					'defaults_only' => [
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'When true, return only the bundled prebuilt layouts and skip the saved-posts query.', 'newspack-newsletters' ),
					],
				],
			]
		);
		\register_rest_route(
			self::API_NAMESPACE,
			'settings',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'api_get_settings' ],
				'permission_callback' => [ __CLASS__, 'api_administration_permissions_check' ],
			]
		);
		\register_rest_route(
			self::API_NAMESPACE,
			'settings',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ __CLASS__, 'api_set_settings' ],
				'permission_callback' => [ __CLASS__, 'api_administration_permissions_check' ],
				'args'                => [
					'mailchimp_api_key' => [
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
		\register_rest_route(
			self::API_NAMESPACE,
			'color-palette',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ __CLASS__, 'api_set_color_palette' ],
				'permission_callback' => [ __CLASS__, 'api_edit_posts_permissions_check' ],
			]
		);

		\register_rest_route(
			self::API_NAMESPACE,
			'post-mjml',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ __CLASS__, 'api_get_mjml' ],
				'permission_callback' => [ __CLASS__, 'api_edit_post_permissions_check' ],
				'args'                => [
					'post_id' => [
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'content' => [
						'required' => true,
					],
				],
			]
		);
		\register_rest_route(
			self::API_NAMESPACE,
			'post-html',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'api_get_post_html' ],
				'permission_callback' => [ __CLASS__, 'api_authoring_permissions_check' ],
				'args'                => [
					'post_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	/**
	 * The default color palette lives in the editor frontend and is not
	 * retrievable on the backend. The workaround is to set it as an option
	 * so that it's available to the email renderer.
	 *
	 * The editor can send multiple color palettes, so we're merging them.
	 *
	 * @param WP_REST_Request $request API request object.
	 */
	public static function api_set_color_palette( $request ) {
		/*
		 * The newsletter editor auto-POSTs the palette on every editor load, including for
		 * Contributors/Authors who can now reach the editor (via post-mjml) but must not
		 * change this site-wide option. We deliberately return success WITHOUT writing for
		 * those roles instead of a 403 — otherwise the editor surfaces a "You cannot use
		 * this resource." notice on every load. So for unauthorized roles the response
		 * reports success while the option write is a no-op. The write capability is
		 * filterable via `newspack_newsletters_color_palette_capability`.
		 */
		$capability = apply_filters( 'newspack_newsletters_color_palette_capability', 'edit_others_posts' );
		$did_write  = false;
		if ( current_user_can( $capability ) ) {
			// update_option() returns false when the value is unchanged as well as on
			// failure, so `updated` reports "the stored palette changed", not "no error".
			$did_write = self::update_color_palette( json_decode( $request->get_body(), true ) );
		} else {
			Newspack_Newsletters_Logger::log( 'Color palette write skipped: current user lacks the "' . $capability . '" capability.' );
		}
		// The route's contract is always 200; the body distinguishes a real write from a
		// permission-skipped no-op so a client or maintainer can tell them apart.
		return \rest_ensure_response( [ 'updated' => (bool) $did_write ] );
	}

	/**
	 * Get MJML markup for a post.
	 * Content is sent straight from the editor, because all this happens
	 * before post is saved in the database.
	 *
	 * @param WP_REST_Request $request API request object.
	 */
	public static function api_get_mjml( $request ) {
		$post = get_post( $request['post_id'] );
		if ( ! empty( $request['title'] ) ) {
			$post->post_title = $request['title'];
		}
		$post->post_content = $request['content'];
		return \rest_ensure_response( Newspack_Newsletters_Renderer::render_post_to_mjml( $post ) );
	}

	/**
	 * Render a newsletter to final email HTML via the WC engine.
	 *
	 * Produces email-safe HTML through the block-based WC email-editor engine for
	 * the editor preview. This is a read-only endpoint: it renders the
	 * newsletter's saved content and, unlike api_get_mjml(), does not accept a
	 * live `content` override, because the WC engine re-fetches the post from the
	 * database by ID at render time (see Post_Content::render_stateless in the
	 * email-editor package), so an in-memory override would be ignored.
	 *
	 * @param WP_REST_Request $request API request object.
	 * @return WP_REST_Response|WP_Error Response carrying the rendered HTML; a 404
	 *                                   error when the post is not a newsletter, or
	 *                                   a 500 error when rendering fails.
	 */
	public static function api_get_post_html( $request ) {
		$post = get_post( $request['post_id'] );
		if ( ! $post instanceof \WP_Post || ! self::validate_newsletter_id( $post->ID ) ) {
			return new \WP_Error(
				'newspack_newsletters_no_post',
				__( 'Newsletter not found.', 'newspack-newsletters' ),
				[ 'status' => 404 ]
			);
		}
		$html = \Newspack\Newsletters\Email_Renderers\Renderer_Controller::render_wc( $post );
		if ( '' === $html ) {
			return new \WP_Error(
				'newspack_newsletters_render_failed',
				__( 'Failed to render the newsletter.', 'newspack-newsletters' ),
				[ 'status' => 500 ]
			);
		}
		return \rest_ensure_response( [ 'html' => $html ] );
	}

	/**
	 * Validate ID is a Newsletter post type.
	 *
	 * @param int $id Post ID.
	 */
	public static function validate_newsletter_id( $id ) {
		if ( ! $id ) {
			return false;
		}
		return self::NEWSPACK_NEWSLETTERS_CPT === get_post_type( $id );
	}

	/**
	 * Retrieve Layouts.
	 *
	 * @param \WP_REST_Request $request Request object.
	 */
	public static function api_get_layouts( $request ) {
		if ( $request && $request->get_param( 'defaults_only' ) ) {
			return \rest_ensure_response(
				array_merge(
					Newspack_Newsletters_Layouts::get_default_layouts(),
					\apply_filters( 'newspack_newsletters_templates', [] )
				)
			);
		}
		$layouts = Newspack_Newsletters_Layouts::get_layouts();

		/*
		 * The layouts list is readable at `edit_posts` so Contributors/Authors can pick a
		 * layout, but each saved layout's `campaign_defaults` carries send/audience config
		 * (senderEmail, send_list_id, send_sublist_id) that the editor copies into the draft.
		 * Withhold it from roles below `edit_others_posts` so the send/audience surface stays
		 * editor-only — the picker still applies content, colors and fonts without it.
		 */
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			foreach ( $layouts as $layout ) {
				if ( isset( $layout->meta ) && is_array( $layout->meta ) ) {
					unset( $layout->meta['campaign_defaults'] );
				}
			}
		}
		return \rest_ensure_response( $layouts );
	}

	/**
	 * Retrieve service API settings for API endpoints.
	 */
	public static function api_get_settings() {
		return \rest_ensure_response( self::api_settings() );
	}

	/**
	 * Set API settings.
	 *
	 * @param WP_REST_Request $request API request object.
	 */
	public static function api_set_settings( $request ) {
		$service_provider = $request['service_provider'];
		$credentials      = $request['credentials'];
		$wp_error         = new WP_Error();

		if ( ! is_string( $service_provider ) || '' === $service_provider ) {
			$wp_error->add(
				'newspack_newsletters_no_service_provider',
				__( 'Please select a newsletter service provider.', 'newspack-newsletters' ),
				[ 'status' => 400 ]
			);
			return $wp_error;
		}

		if ( 'manual' === $service_provider ) {
			self::set_service_provider( $service_provider );
			return self::api_get_settings();
		}

		if ( ! is_array( $credentials ) || empty( $credentials ) ) {
			$wp_error->add(
				'newspack_newsletters_invalid_keys',
				__( 'Please input credentials.', 'newspack-newsletters' ),
				[ 'status' => 400 ]
			);
			return $wp_error;
		}

		// Only commit set_service_provider on credentials success — a rejection must not leave the site pointing at an unconfigured ESP.
		$provider = self::get_service_provider_instance( $service_provider );
		if ( ! $provider || ! method_exists( $provider, 'set_api_credentials' ) ) {
			$wp_error->add(
				'newspack_newsletters_provider_unavailable',
				__( 'The selected service provider is not available on this site.', 'newspack-newsletters' ),
				[ 'status' => 400 ]
			);
			return $wp_error;
		}

		$status = $provider->set_api_credentials( $credentials );
		if ( is_wp_error( $status ) ) {
			foreach ( $status->errors as $code => $message ) {
				$wp_error->add( $code, implode( ' ', $message ), [ 'status' => 400 ] );
			}
			return $wp_error;
		}

		self::set_service_provider( $service_provider );
		return self::api_get_settings();
	}

	/**
	 * Whether the current user can manage admin settings.
	 *
	 * @return bool Whether the current user can manage admin settings.
	 */
	public static function api_permission_callback() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Retrieve settings.
	 */
	public static function api_settings() {
		$service_provider = self::service_provider();
		$is_esp_manual    = 'manual' === $service_provider;
		$response         = [
			'service_provider' => $service_provider ? $service_provider : '',
			'status'           => false,
		];
		if ( self::$provider ) {
			$response['credentials'] = self::$provider->api_credentials();
		}
		if ( $is_esp_manual || ( self::$provider && self::$provider->has_api_credentials() ) ) {
			$response['status'] = true;
		}

		return $response;
	}

	/**
	 * Are all the needed API credentials available?
	 *
	 * @return bool Whether all API credentials are set.
	 */
	public static function is_service_provider_configured() {
		$settings = self::api_settings();
		return $settings['status'];
	}

	/**
	 * Check capabilities for using the API for administration tasks.
	 *
	 * @param WP_REST_Request $request API request object.
	 * @return bool|WP_Error
	 */
	public static function api_administration_permissions_check( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'newspack_rest_forbidden',
				esc_html__( 'You cannot use this resource.', 'newspack-newsletters' ),
				[
					'status' => 403,
				]
			);
		}
		return true;
	}

	/**
	 * Check capabilities for using the API for authoring tasks.
	 *
	 * @param WP_REST_Request $request API request object.
	 * @return bool|WP_Error
	 */
	public static function api_authoring_permissions_check( $request ) {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return new \WP_Error(
				'newspack_rest_forbidden',
				esc_html__( 'You cannot use this resource.', 'newspack-newsletters' ),
				[
					'status' => 403,
				]
			);
		}
		return true;
	}

	/**
	 * Permission check for post-scoped authoring routes (e.g. `post-mjml`):
	 * the current user must be able to edit the specific post the request
	 * targets. Scoped on `post_id` only — never a generic `id`, which on
	 * other routes refers to a different CPT (e.g. a layout).
	 *
	 * @param WP_REST_Request $request API request object.
	 * @return bool|WP_Error
	 */
	public static function api_edit_post_permissions_check( $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		if ( $post_id && current_user_can( 'edit_post', $post_id ) ) {
			return true;
		}
		return new \WP_Error(
			'newspack_rest_forbidden',
			esc_html__( 'You cannot use this resource.', 'newspack-newsletters' ),
			[
				'status' => 403,
			]
		);
	}

	/**
	 * Permission check for non-post authoring reads needed to load the
	 * editor (e.g. the `layouts` list of saved templates). Any user who can
	 * author posts may use them. These surface editor-support content; the
	 * one field that carries send/audience configuration (`campaign_defaults`)
	 * is stripped from the layouts payload for roles below `edit_others_posts`
	 * in api_get_layouts(), so this relaxed check does not broaden that surface.
	 *
	 * @param WP_REST_Request $request API request object.
	 * @return bool|WP_Error
	 */
	public static function api_edit_posts_permissions_check( $request ) {
		unset( $request );
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}
		return new \WP_Error(
			'newspack_rest_forbidden',
			esc_html__( 'You cannot use this resource.', 'newspack-newsletters' ),
			[
				'status' => 403,
			]
		);
	}

	/**
	 * Set initial title of newsletter.
	 *
	 * @param string  $post_title Post title.
	 * @param WP_Post $post Post.
	 * @return string Title.
	 */
	public static function default_title( $post_title, $post ) {
		if ( self::NEWSPACK_NEWSLETTERS_CPT === get_post_type( $post ) ) {
			$post_title = gmdate( get_option( 'date_format' ) );
		}
		return $post_title;
	}

	/**
	 * Handle custom Newsletter styling when viewing the newsletter as a public post.
	 */
	public static function public_newsletter_custom_style() {
		if ( ! is_single() ) {
			return;
		}
		$post = get_post();
		if ( $post && self::NEWSPACK_NEWSLETTERS_CPT === $post->post_type ) {
			$font_header      = get_post_meta( $post->ID, 'font_header', true );
			$font_body        = get_post_meta( $post->ID, 'font_body', true );
			$background_color = get_post_meta( $post->ID, 'background_color', true );
			$text_color       = get_post_meta( $post->ID, 'text_color', true );
			?>
				<style>
					.main-content {
						background-color: <?php echo esc_attr( $background_color ); ?>;
						font-family: <?php echo esc_attr( $font_body ); ?>;
						color: <?php echo esc_attr( $text_color ); ?>;
					}
					.main-content h1,
					.main-content h2,
					.main-content h3,
					.main-content h4,
					.main-content h5,
					.main-content h6 {
						font-family: <?php echo esc_attr( $font_header ); ?>;
					}
					<?php if ( $background_color ) : ?>
						.entry-content {
							padding: 0 32px;;
						}
					<?php endif; ?>

					/* Social Links Block Styles */
					.wp-block-social-links {
						gap: 0 !important;
					}
					.wp-block-social-links.is-style-circle-black .wp-social-link {
						background: black;
						color: white;
					}
					.wp-block-social-links.is-style-filled-black .wp-social-link {
						background: transparent;
						color: black;
					}
					.wp-block-social-links.is-style-circle-white .wp-social-link {
						background: white;
						color: black;
					}
					.wp-block-social-links.is-style-filled-white .wp-social-link {
						background: transparent;
						color: white;
					}
				</style>
			<?php
		}
	}

	/**
	 * Activation Nag
	 */

	/**
	 * Add admin notice if API credentials are unset.
	 */
	public static function activation_nag() {
		$screen = get_current_screen();
		// Match both the legacy and React settings screens — neither should show the "head to settings" nag.
		$on_settings_screen = $screen && is_string( $screen->base ) && false !== strpos( $screen->base, 'newspack-newsletters-settings' );
		if ( $on_settings_screen || ( $screen && self::NEWSPACK_NEWSLETTERS_CPT === $screen->post_type ) ) {
			return;
		}
		$url = Newspack_Newsletters_Settings::get_settings_url();
		?>
		<div class="notice notice-info is-dismissible newspack-newsletters-notification-nag">
			<p>
				<?php
					echo wp_kses_post(
						sprintf(
							// translators: urge users to input their API credentials on settings page.
							__( 'Thank you for activating Newspack Newsletters. Please <a href="%s">head to settings</a> to set up your API credentials.', 'newspack-newsletters' ),
							$url
						)
					);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Enqueue style to handle Newspack branding.
	 */
	public static function branding_scripts() {
		$screen = get_current_screen();
		if (
			self::NEWSPACK_NEWSLETTERS_CPT !== $screen->post_type &&
			Newspack_Newsletters\Ads::CPT !== $screen->post_type &&
			Newspack\Newsletters\Subscription_Lists::CPT !== $screen->post_type &&
			Newspack_Newsletters_Layouts::NEWSPACK_NEWSLETTERS_LAYOUT_CPT !== $screen->post_type
		) {
			return;
		}

		// Banner is bundled-only — mirror `Admin_Shell::is_bundled_mode()` so the filter override drops it from a standalone shell too.
		$is_bundled = class_exists( '\Newspack\Newsletters\Admin\Admin_Shell' )
			? \Newspack\Newsletters\Admin\Admin_Shell::is_bundled_mode()
			: class_exists( '\Newspack\Newspack' );
		if ( ! $is_bundled ) {
			return;
		}

		$script = 'newspack-newsletters-branding_scripts';
		wp_enqueue_script(
			$script,
			plugins_url( '../dist/branding.js', __FILE__ ),
			[ 'jquery' ],
			'1.0',
			false
		);
		wp_enqueue_style(
			$script,
			plugins_url( '../dist/branding.css', __FILE__ ),
			[],
			'1.0',
			'screen'
		);
	}

	/**
	 * Enqueue script to handle activation nag dismissal.
	 */
	public static function activation_nag_dismissal_script() {
		$script = 'newspack-newsletters-activation_nag_dismissal';
		wp_register_script(
			$script,
			plugins_url( '../dist/admin.js', __FILE__ ),
			[ 'jquery' ],
			'1.0',
			false
		);
		wp_localize_script(
			$script,
			'newspack_newsletters_activation_nag_dismissal_params',
			[
				'ajaxurl' => get_admin_url() . 'admin-ajax.php',
			]
		);
		wp_enqueue_script( $script );
	}

	/**
	 * AJAX callback after nag has been dismissed.
	 */
	public static function activation_nag_dismissal_ajax() {
		update_option( 'newspack_newsletters_activation_nag_viewed', true );
	}

	/**
	 * Is wp-config debug flag set.
	 *
	 * @return boolean Is debug mode on?
	 */
	public static function debug_mode() {
		/**
		 * Enables debug mode for Newspack Newsletters, providing additional
		 * logging and diagnostic information for troubleshooting email
		 * campaign issues.
		 *
		 * @constant NEWSPACK_NEWSLETTERS_DEBUG_MODE
		 * @type     bool
		 * @default  Debug mode disabled
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_NEWSLETTERS_DEBUG_MODE', true );
		 */
		return defined( 'NEWSPACK_NEWSLETTERS_DEBUG_MODE' ) ? NEWSPACK_NEWSLETTERS_DEBUG_MODE : false;
	}

	/**
	 * Which Email Service Provider should be used.
	 *
	 * @return string Name of the Email Service Provider.
	 */
	public static function service_provider() {
		return get_option( 'newspack_newsletters_service_provider', false );
	}

	/**
	 * If using a Newspack theme, add support for featured image options.
	 *
	 * @param array $post_types Array of supported post types.
	 * @return array Filtered array of supported post types.
	 */
	public static function support_featured_image_options( $post_types ) {
		return array_merge(
			$post_types,
			[ self::NEWSPACK_NEWSLETTERS_CPT ]
		);
	}

	/**
	 * Prevent Gravityforms from injecting scripts into the newsletter markup.
	 *
	 * @param bool $force_js Whether to force GF to inject scripts (default: true).
	 * @return bool Modified $force_js.
	 */
	public static function suppress_gravityforms_js_on_newsletters( $force_js ) {
		if ( self::NEWSPACK_NEWSLETTERS_CPT === get_post_type() ) {
			return false;
		}

		return $force_js;
	}

	/**
	 * Hide blocks whose `newsletterVisibility` doesn't match the current render.
	 *
	 * On the web front-end, `email`-only blocks are hidden. During an email render
	 * (`render_wc`, detected via the rendering-post accessor) it's the opposite:
	 * `web`-only blocks are hidden and `email`-only blocks are kept. Without the
	 * email branch, `render_wc` followed the web path and wrongly dropped email-only
	 * blocks — e.g. the prebuilt layouts' "Support our newsroom" section.
	 *
	 * @param string $block_content The block content about to be appended.
	 * @param array  $block         The full block, including name and attributes.
	 * @return string The block content, or '' when the block is hidden in this context.
	 */
	public static function remove_visibility_hidden_block( $block_content, $block ) {
		if ( self::NEWSPACK_NEWSLETTERS_CPT !== get_post_type() || empty( $block['attrs']['newsletterVisibility'] ) ) {
			return $block_content;
		}
		$is_email_render   = class_exists( '\Newspack\Newsletters\Email_Renderers\Renderer_Controller' )
			&& \Newspack\Newsletters\Email_Renderers\Renderer_Controller::get_rendering_post() instanceof \WP_Post;
		$hidden_visibility = $is_email_render ? 'web' : 'email';
		return $hidden_visibility === $block['attrs']['newsletterVisibility'] ? '' : $block_content;
	}

	/**
	 * Get mailing lists of the configured ESP.
	 */
	public static function get_esp_lists() {
		if ( self::is_service_provider_configured() ) {
			if ( 'manual' === self::service_provider() ) {
				return new WP_Error(
					'newspack_newsletters_manual_lists',
					__( 'Lists not available while using manual configuration.', 'newspack-newsletters' )
				);
			}
			if ( ! self::$provider ) {
				return new WP_Error(
					'newspack_newsletters_esp_not_a_provider',
					__( 'Lists not available for the current Newsletters setup.', 'newspack-newsletters' )
				);
			}
			try {
				return self::$provider->get_lists();
			} catch ( \Exception $e ) {
				return new WP_Error(
					'newspack_newsletters_get_lists',
					$e->getMessage()
				);
			}
		}
		return [];
	}

	/**
	 * Mark newsletter as sent.
	 *
	 * @param int $post_id Post ID.
	 * @param int $time    Optional timestamp to mark as sent. Default is now.
	 */
	public static function set_newsletter_sent( $post_id, $time = 0 ) {
		update_post_meta( $post_id, 'newsletter_sent', 0 < $time ? $time : time() );
	}

	/**
	 * Whether the newsletter has been marked as sent.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return false|int False if not sent, or timestamp of when it was sent.
	 */
	public static function is_newsletter_sent( $post_id ) {
		/** Handle scheduled newsletter state. */
		$sending_scheduled = get_post_meta( $post_id, 'sending_scheduled', true );
		if ( $sending_scheduled ) {
			return false;
		}

		/** Handle scheduled newsletter error. */
		$scheduling_error = get_post_meta( $post_id, 'scheduling_error', true );
		if ( $scheduling_error ) {
			return false;
		}

		/** Detect meta that determines the sent state */
		$sent          = get_post_meta( $post_id, 'newsletter_sent', true );
		$post_status   = get_post_status( $post_id );
		$is_published  = 'publish' === $post_status || 'private' === $post_status;
		$post_datetime = $is_published ? get_post_datetime( $post_id, 'date', 'gmt' ) : false;
		$publish_date  = $post_datetime ? $post_datetime->getTimestamp() : 0;
		if ( 0 < $sent && $sent === $publish_date ) {
			return $sent;
		}

		if ( $publish_date ) {
			self::set_newsletter_sent( $post_id, $publish_date );
			return $publish_date;
		}

		return false;
	}

	/**
	 * Display newsletters in archive pages.
	 *
	 * @param WP_Query $query The query.
	 */
	public static function display_newsletters_in_archives( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( $query->is_tag() || $query->is_category() || $query->is_author() ) {
			$post_type = $query->get( 'post_type' );
			if ( empty( $post_type ) ) {
				$post_type = [ 'post' ];
			}
			if ( ! is_array( $post_type ) ) {
				$post_type = [ $post_type ];
			}
			$post_type[] = self::NEWSPACK_NEWSLETTERS_CPT;
			$query->set( 'post_type', $post_type );
		}
	}

	/**
	 * Fix the post status of a newsletter. Ensures a newsletter is 'private' if
	 * the 'is_public' is not found or false.
	 *
	 * @param WP_Post $post The post object.
	 */
	public static function fix_public_status( $post ) {
		// Only run if it's a newsletter post.
		if ( ! self::validate_newsletter_id( $post->ID ) ) {
			return;
		}
		$is_public = (bool) get_post_meta( $post->ID, 'is_public', true );
		if ( 'publish' === $post->post_status && ! $is_public ) {
			// Correcting to `private` does NOT trigger an ESP send. A
			// publish -> private transition stays within the controlled
			// statuses (`['publish', 'private']`), so neither `pre_post_update()`
			// (which sends when a newsletter moves out of / into that set) nor
			// `transition_post_status()` (which sends only when `$old_status` is
			// `'future'`) fires. This matters because, with the `exit` below now
			// scoped to page views, a single request can correct many rows
			// instead of stopping at the first — many corrections, still no sends.
			wp_update_post(
				[
					'ID'          => $post->ID,
					'post_status' => 'private',
				],
				false,
				false
			);
			// Force a page refresh, but only on a genuine front-end page view.
			// During REST, AJAX, cron or WP-CLI the `exit` would truncate the
			// current request (e.g. a REST collection would return a partial,
			// short-circuited response), so restrict it to real page loads.
			if ( self::is_front_end_page_request() ) {
				header( 'Refresh:0' );
				exit;
			}
		}
	}

	/**
	 * Whether the current request is a genuine front-end page view, as opposed
	 * to an admin screen, REST/AJAX/XML-RPC request, cron run, WP-CLI
	 * invocation, or feed render.
	 *
	 * Used to decide whether it is safe to `exit` the request: on a page view a
	 * redirect is the intended behavior, but on any programmatic request or
	 * streamed response an `exit` would truncate the output mid-flight.
	 *
	 * @return bool True on a front-end page request, false otherwise.
	 */
	private static function is_front_end_page_request() {
		if ( is_admin() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( wp_doing_ajax() ) {
			return false;
		}
		if ( wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}
		// A feed is a front-end request but not a page view; an `exit` here
		// would truncate the feed's XML mid-document. Newsletters can appear in
		// feeds via `display_newsletters_in_archives()`. Behaviour change worth
		// naming: a feed containing a just-corrected newsletter now serves that
		// item once in the current response (a reader hitting the feed at that
		// moment sees it) rather than emitting invalid XML, and it drops out of
		// subsequent requests once healed — one slightly-stale item beats broken
		// XML.
		//
		// This check MUST stay after the REST/AJAX/cron/CLI/XML-RPC returns
		// above — the ordering is load-bearing, not stylistic. `is_feed()` is a
		// main-query conditional; called with `$wp_query` unset (e.g. mid-REST)
		// it triggers `_doing_it_wrong()` and returns false, which would let the
		// `exit` through in exactly the contexts this guard protects. Do not
		// reorder these into alphabetical / "cheapest check first" order.
		if ( is_feed() ) {
			return false;
		}
		return true;
	}

	/**
	 * Updates the default newsletters color palette option.
	 *
	 * @param array $palette The updated color palette.
	 *
	 * @return bool True if the option was updated, false otherwise.
	 */
	public static function update_color_palette( $palette ) {
		return update_option(
			self::NEWSPACK_NEWSLETTERS_PALETTE_META,
			wp_json_encode(
				array_merge(
					json_decode( (string) get_option( self::NEWSPACK_NEWSLETTERS_PALETTE_META, '{}' ), true ) ?? [],
					$palette
				)
			)
		);
	}

	/**
	 * Whether the current user may set a newsletter's public-page flag to a given value.
	 *
	 * The flag is not a label: the active service provider watches it and moves the post
	 * between `private` and `publish` to match, so setting it true publishes a page.
	 * Making one public therefore needs `publish_post`, the same bar as publishing any
	 * other content -- `edit_post` alone is satisfied by an author's own unpublished
	 * post. Making one non-public de-escalates and needs only `edit_post`.
	 *
	 * This is the single definition of that rule. The list-table bulk action and the
	 * classic quick-edit call it to decide what to skip, and `guard_public_status_write()`
	 * enforces it on every write, including the REST meta route the admin shell uses.
	 *
	 * @param int  $post_id   Newsletter post ID.
	 * @param bool $is_public The value being set.
	 *
	 * @return bool
	 */
	public static function current_user_can_set_public_status( $post_id, $is_public ) {
		// `edit_post` carries the ownership and status logic; it is what stops a user
		// touching someone else's newsletter. `publish_post` maps to the primitive
		// `publish_posts` and never consults the post's author, so it is an addition
		// to that check and never a replacement for it -- on its own it would let an
		// Author publish anyone's newsletter.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}
		if ( $is_public ) {
			return current_user_can( 'publish_post', $post_id );
		}
		return true;
	}

	/**
	 * Enforce the public-page capability rule on every write of the meta.
	 *
	 * The entry points check the rule themselves so they can report what they skipped,
	 * but they are not the only writers: `is_public` is registered with `show_in_rest`
	 * and an `auth_callback` of `__return_true`, so a REST meta write is gated on
	 * `edit_post` alone. Filtering the write itself covers that route and any writer
	 * added later, which is the part a per-caller check cannot promise.
	 *
	 * Returning a non-null value short-circuits the write in `update_metadata()`.
	 *
	 * @param null|bool $check      Short-circuit value. Non-null blocks the write.
	 * @param int       $post_id    Post ID.
	 * @param string    $meta_key   Meta key.
	 * @param mixed     $meta_value Value being written.
	 *
	 * @return null|bool
	 */
	public static function guard_public_status_write( $check, $post_id, $meta_key, $meta_value ) {
		if ( null !== $check || 'is_public' !== $meta_key ) {
			return $check;
		}
		if ( self::NEWSPACK_NEWSLETTERS_CPT !== get_post_type( $post_id ) ) {
			return $check;
		}
		// A capability is something a user holds, and there is no user on a WP-CLI run,
		// a cron event or a migration -- `current_user_can()` is false for all of them,
		// so enforcing here would block legitimate automation rather than an actor.
		// Every HTTP route that reaches this write authenticates first, so the escape
		// does not open one.
		if ( ! is_user_logged_in() ) {
			return $check;
		}
		if ( self::current_user_can_set_public_status( $post_id, (bool) $meta_value ) ) {
			return $check;
		}
		return false;
	}
}
Newspack_Newsletters::instance();
