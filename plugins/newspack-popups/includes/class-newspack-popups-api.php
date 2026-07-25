<?php
/**
 * Newspack Popups API
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * API endpoints
 */
final class Newspack_Popups_API {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_api_endpoints' ] );
	}

	/**
	 * Register REST API endpoints.
	 */
	public function register_api_endpoints() {
		\register_rest_route(
			'newspack-popups/v1',
			'settings',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'update_settings_standalone' ],
				'permission_callback' => [ $this, 'permission_callback' ],
				'args'                => [
					'settingsToUpdate' => [
						'validate_callback' => [ __CLASS__, 'validate_settings' ],
						'sanitize_callback' => [ __CLASS__, 'sanitize_array' ],
					],
				],
			]
		);

		\register_rest_route(
			'newspack-popups/v1',
			'prompts',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_inline_and_manual_prompts' ],
				'permission_callback' => [ $this, 'permission_callback' ],
				'args'                => [
					'search'   => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'_fields'  => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'include'  => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'per_page' => [
						'type'              => 'integer',
						'default'           => 10,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		\register_rest_route(
			'newspack-popups/v1',
			'/(?P<original_id>\d+)/(?P<id>\d+)/duplicate',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_duplicate_title' ],
				'permission_callback' => [ $this, 'permission_callback' ],
				'args'                => [
					'original_id' => [
						'sanitize_callback' => 'absint',
					],
					'id'          => [
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		\register_rest_route(
			'newspack-popups/v1',
			'/(?P<id>\d+)/duplicate',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_duplicate_popup' ],
				'permission_callback' => [ $this, 'permission_callback' ],
				'args'                => [
					'id'    => [
						'sanitize_callback' => 'absint',
					],
					'title' => [
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// API endpoints for RAS presets.
		register_rest_route(
			'newspack-popups/v1',
			'/audience-management/campaign',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_reader_activation_campaign_settings' ],
				'permission_callback' => [ $this, 'permission_callback' ],
			]
		);
		register_rest_route(
			'newspack-popups/v1',
			'/audience-management/campaign',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_update_reader_activation_campaign_settings' ],
				'permission_callback' => [ $this, 'permission_callback' ],
			]
		);
		if ( Newspack_Popups::is_contextual_prompts_enabled() ) {
			register_rest_route(
				'newspack-popups/v1',
				'/contextual-prompt/status',
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'api_get_contextual_prompt_status' ],
					'permission_callback' => function () {
						return current_user_can( 'edit_posts' );
					},
				]
			);
			register_rest_route(
				'newspack-popups/v1',
				'/contextual-prompt/enable',
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					// Opting into AI use is an administrator decision.
					'callback'            => [ __CLASS__, 'api_set_contextual_prompt_enabled' ],
					'permission_callback' => [ $this, 'permission_callback' ],
					'args'                => [
						'enabled' => [
							'required'          => true,
							'sanitize_callback' => 'rest_sanitize_boolean',
						],
					],
				]
			);
			register_rest_route(
				'newspack-popups/v1',
				'/contextual-prompt/profile',
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ __CLASS__, 'api_save_contextual_prompt_profile' ],
					'permission_callback' => [ $this, 'permission_callback' ],
					'args'                => [
						'fields' => [
							'required' => true,
							'type'     => 'object',
						],
						'styles' => [
							'required' => false,
							'type'     => 'object',
						],
					],
				]
			);
		}
	}

	/**
	 * Report whether Contextual Prompts is opted into, and whether the current
	 * user is allowed to change that.
	 *
	 * @return WP_REST_Response
	 */
	public static function api_get_contextual_prompt_status() {
		return rest_ensure_response( self::contextual_prompt_status() );
	}

	/**
	 * The Contextual Prompts status payload: opt-in state, whether the user can
	 * manage it, the publisher-profile fields, and the block's style data.
	 *
	 * @return array
	 */
	private static function contextual_prompt_status() {
		// Styles are a keyed object to every client, so no overrides has to travel
		// as `{}`: an empty PHP array would serialize as `[]` and a client
		// comparing it against an object it built would never see them as equal.
		$styles     = Newspack_Popups_Contextual_Prompt_Styles::get_styles();
		$font_sizes = self::flatten_global_settings_presets( wp_get_global_settings( [ 'typography', 'fontSizes' ] ) );
		return [
			'enabled'                => Newspack_Popups_Settings::is_ai_copy_assistant_enabled(),
			'can_manage'             => current_user_can( 'manage_options' ),
			'fields'                 => Newspack_Popups_Settings::get_ai_copy_assistant_fields(),
			'override_active'        => Newspack_Popups_Settings::is_override_active(),
			'is_block_theme'         => wp_is_block_theme(),
			'styles'                 => empty( $styles ) ? (object) [] : $styles,
			'style_defaults'         => Newspack_Popups_Contextual_Prompt_Styles::get_defaults(),
			'style_palette'          => self::merge_global_settings_presets( wp_get_global_settings( [ 'color', 'palette' ] ) ),
			'style_font_sizes'       => self::normalize_preset_font_sizes( $font_sizes ),
			'site_editor_styles_url' => admin_url( 'site-editor.php?p=%2Fstyles&section=' . rawurlencode( '/blocks/' . rawurlencode( Newspack_Popups_Contextual_Prompt_Block::BLOCK_NAME ) ) ),
		];
	}

	/**
	 * The preset lists that hold something, highest precedence first. Global
	 * settings key presets by origin (custom > theme > default), each origin holding
	 * its own list; an already-flat list stands in as its own single origin. Neither
	 * shape means it is not a preset list at all — a missing settings path hands back
	 * the whole settings tree — so it offers nothing.
	 *
	 * @param mixed $presets Origin-keyed presets, or already-flat array.
	 * @return array List of preset lists, empty when there is nothing to offer.
	 */
	private static function preset_origin_lists( $presets ) {
		if ( ! is_array( $presets ) ) {
			return [];
		}
		// Not origin-keyed: already a flat preset list.
		if ( wp_is_numeric_array( $presets ) ) {
			return empty( $presets ) ? [] : [ array_values( $presets ) ];
		}
		$lists = [];
		foreach ( [ 'custom', 'theme', 'default' ] as $origin ) {
			if ( ! empty( $presets[ $origin ] ) && is_array( $presets[ $origin ] ) ) {
				$lists[] = array_values( $presets[ $origin ] );
			}
		}
		return $lists;
	}

	/**
	 * One flat preset list, taking the highest origin that holds anything. This is
	 * how the editor resolves a preset list it offers as a single set — font sizes,
	 * where `custom ?? theme ?? default` decides — so the wizard offers the same
	 * sizes its picker does.
	 *
	 * Public so the flattening can be exercised directly in tests.
	 *
	 * @param mixed $presets Origin-keyed presets, or already-flat array.
	 * @return array Flat preset list, empty when there is nothing to offer.
	 */
	public static function flatten_global_settings_presets( $presets ) {
		$lists = self::preset_origin_lists( $presets );
		return empty( $lists ) ? [] : $lists[0];
	}

	/**
	 * One flat preset list holding every origin, custom beating theme beating
	 * default on a shared slug. The editor's color panel shows each origin as its
	 * own section rather than picking one, and every origin gets a CSS custom
	 * property, so dropping the lower ones would hide usable colors.
	 *
	 * Public so the merge can be exercised directly in tests.
	 *
	 * @param mixed $presets Origin-keyed presets, or already-flat array.
	 * @return array Flat preset list, empty when there is nothing to offer.
	 */
	public static function merge_global_settings_presets( $presets ) {
		$flat  = [];
		$slugs = [];
		foreach ( self::preset_origin_lists( $presets ) as $list ) {
			foreach ( $list as $preset ) {
				if ( ! is_array( $preset ) ) {
					continue;
				}
				$slug = isset( $preset['slug'] ) ? (string) $preset['slug'] : '';
				if ( '' !== $slug ) {
					if ( isset( $slugs[ $slug ] ) ) {
						continue;
					}
					$slugs[ $slug ] = true;
				}
				$flat[] = $preset;
			}
		}
		return $flat;
	}

	/**
	 * Font size presets can carry a plain number: core unit-izes the ones a theme
	 * registers with `add_theme_support( 'editor-font-sizes' )`, but not the ones a
	 * theme.json declares. The wizard stores CSS strings and the style sanitizer
	 * keeps string leaves only, so a numeric size would be offered in the picker
	 * and dropped on save. Deliver every size in px, as core's own back-compat does.
	 *
	 * @param array $presets Flat font size presets.
	 * @return array
	 */
	private static function normalize_preset_font_sizes( $presets ) {
		foreach ( $presets as $index => $preset ) {
			if ( is_array( $preset ) && isset( $preset['size'] ) && is_numeric( $preset['size'] ) ) {
				$presets[ $index ]['size'] = $preset['size'] . 'px';
			}
		}
		return $presets;
	}

	/**
	 * Save the Contextual Prompts publisher profile. Administrator-only.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function api_save_contextual_prompt_profile( $request ) {
		$enabled = self::require_contextual_prompts_enabled();
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}

		Newspack_Popups_Settings::save_ai_copy_assistant_fields( (array) $request['fields'] );
		if ( isset( $request['styles'] ) ) {
			Newspack_Popups_Contextual_Prompt_Styles::save_styles( (array) $request['styles'] );
		}
		return rest_ensure_response( self::contextual_prompt_status() );
	}

	/**
	 * Guard for endpoints that must stay inert until an administrator opts the site
	 * into AI use. Some newsrooms are contractually barred from using AI, so the
	 * feature reads and writes nothing before opt-in — see NPPD-2095. The opt-in
	 * status/enable endpoints are deliberately exempt; they are how opt-in happens.
	 *
	 * @return true|\WP_Error True when enabled, WP_Error otherwise.
	 */
	private static function require_contextual_prompts_enabled() {
		if ( Newspack_Popups_Settings::is_ai_copy_assistant_enabled() ) {
			return true;
		}

		return new \WP_Error(
			'newspack_contextual_prompts_disabled',
			esc_html__( 'Contextual Prompts is not enabled for this site.', 'newspack-popups' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Opt this site into (or out of) Contextual Prompts. Administrator-only.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function api_set_contextual_prompt_enabled( $request ) {
		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, (bool) $request['enabled'] );
		return rest_ensure_response( self::contextual_prompt_status() );
	}

	/**
	 * Recursively sanitize an array of arbitrary values.
	 *
	 * @param array $array Array to be sanitized.
	 * @return array Sanitized array.
	 */
	public static function sanitize_array( $array ) {
		foreach ( $array as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = self::sanitize_array( $value );
			} elseif ( is_string( $value ) ) {
					$value = sanitize_text_field( $value );
			} elseif ( is_numeric( $value ) ) {
				$value = intval( $value );
			} else {
				$value = boolval( $value );
			}

			$array[ $key ] = $value;
		}

		return $array;
	}

	/**
	 * Validate settings to be updated.
	 *
	 * @param String $settings_to_update Associative array of settings to be updated.
	 */
	public static function validate_settings( $settings_to_update ) {
		$valid = true;

		foreach ( $settings_to_update as $key => $value ) {
			if ( ! self::validate_settings_option_name( $key ) ) {
				$valid = false;
			}
		}

		return $valid;
	}

	/**
	 * Validate settings option key.
	 *
	 * @param String $key Meta key.
	 */
	public static function validate_settings_option_name( $key ) {
		return in_array(
			$key,
			array_map(
				function ( $setting ) {
					return $setting['key'];
				},
				\Newspack_Popups_Settings::get_settings()
			)
		);
	}

	/**
	 * Permission callback for authenticated requests.
	 *
	 * @return boolean if user can edit stuff.
	 */
	public static function permission_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'newspack_rest_forbidden',
				esc_html__( 'You cannot use this resource.', 'newspack' ),
				[
					'status' => 403,
				]
			);
		}
		return true;
	}

	/**
	 * Handler for API settings update endpoint.
	 * This endpoint is used by the standlone Settings page, which
	 * is only used if the main Newspack plugin UI isn't available.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public static function update_settings_standalone( $request ) {
		$settings_to_update = $request['settingsToUpdate'];

		foreach ( $settings_to_update as $key => $value ) {
			$result = \Newspack_Popups_Settings::set_settings_standalone(
				[
					'option_value' => $value,
					'option_name'  => $key,
				]
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return \rest_ensure_response( \Newspack_Popups_Settings::get_settings() );
	}

	/**
	 * Get inline prompts with the given params.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public static function get_inline_and_manual_prompts( $request ) {
		$params   = $request->get_params();
		$search   = ! empty( $params['search'] ) ? $params['search'] : null;
		$include  = ! empty( $params['include'] ) ? explode( ',', $params['include'] ) : null;
		$per_page = ! empty( $params['per_page'] ) ? $params['per_page'] : 10;

		// Query args.
		$args = [
			'post_type'      => Newspack_Popups::NEWSPACK_POPUPS_CPT,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'meta_key'       => 'placement',
			'meta_compare'   => 'IN',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'meta_value'     => [ 'inline', 'manual' ],
		];

		// Look up by title only if provided with a search term and not post IDs.
		if ( ! empty( $search ) && empty( $include ) ) {
			$args['s'] = esc_sql( $search );
		}

		// If given post IDs to include, just get those.
		if ( ! empty( $include ) && count( $include ) && empty( $search ) ) {
			$args['post__in'] = $include;
			$args['orderby']  = 'post__in';
			$args['order']    = 'ASC';
		}

		$query = new \WP_Query( $args );

		if ( $query->have_posts() ) {
			return new \WP_REST_Response(
				array_map(
					function( $post ) {
						$item = [
							'id'      => $post->ID,
							'title'   => $post->post_title,
							'content' => apply_filters( 'the_content', $post->post_content ),
						];

						return $item;
					},
					$query->posts
				),
				200
			);
		}

		return new \WP_REST_Response( [] );
	}

	/**
	 * Get default title for a duplicated prompt.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response with complete info to render the Engagement Wizard.
	 */
	public function api_get_duplicate_title( $request ) {
		$response = Newspack_Popups::get_duplicate_title( $request['original_id'], $request['id'] );
		return rest_ensure_response( $response );
	}

	/**
	 * Duplicate a prompt.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response with complete info to render the Engagement Wizard.
	 */
	public function api_duplicate_popup( $request ) {
		$response = Newspack_Popups::duplicate_popup( $request['id'], $request['title'] );
		return rest_ensure_response( $response );
	}

	/**
	 * Get reader activation campaign settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function api_get_reader_activation_campaign_settings( $request ) {
		$response = Newspack_Popups_Presets::get_ras_presets();

		if ( \is_wp_error( $response ) ) {
			return new \WP_REST_Response( [ 'message' => $response->get_error_message() ], 400 );
		}

		return rest_ensure_response( $response['prompts'] );
	}

	/**
	 * Update reader activation campaign settings.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function api_update_reader_activation_campaign_settings( $request ) {
		$slug = $request['slug'];
		$data = $request['data'];

		$response = Newspack_Popups_Presets::update_preset_prompt( $slug, $data );

		if ( \is_wp_error( $response ) ) {
			return new \WP_REST_Response( [ 'message' => $response->get_error_message() ], 400 );
		}

		return rest_ensure_response( $response['prompts'] );
	}
}
$newspack_popups_api = new Newspack_Popups_API();
