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
				],
			]
		);
		register_rest_route(
			'newspack-popups/v1',
			'/contextual-prompt',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'api_get_scoped_prompt' ],
				'permission_callback' => [ __CLASS__, 'contextual_prompt_permission_callback' ],
				'args'                => [
					'post_id' => [
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
		register_rest_route(
			'newspack-popups/v1',
			'/contextual-prompt',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'api_create_contextual_prompt' ],
				'permission_callback' => [ __CLASS__, 'contextual_prompt_permission_callback' ],
				'args'                => [
					'post_id'          => [
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'prompt_id'        => [ 'sanitize_callback' => 'absint' ],
					'body'             => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					'button_label'     => [ 'sanitize_callback' => 'sanitize_text_field' ],
					'button_url'       => [ 'sanitize_callback' => 'esc_url_raw' ],
					'position'         => [ 'sanitize_callback' => 'absint' ],
					'template_version' => [ 'sanitize_callback' => 'sanitize_text_field' ],
					'request_id'       => [ 'sanitize_callback' => 'sanitize_text_field' ],
					'ai_generated'     => [ 'sanitize_callback' => 'rest_sanitize_boolean' ],
					'ai_edited'        => [ 'sanitize_callback' => 'rest_sanitize_boolean' ],
					'enabled'          => [ 'sanitize_callback' => 'rest_sanitize_boolean' ],
					'reset_design'     => [ 'sanitize_callback' => 'rest_sanitize_boolean' ],
				],
			]
		);
	}

	/**
	 * Permission check for creating a Contextual Prompt: the user must be able to
	 * edit the article the prompt will be scoped to.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public static function contextual_prompt_permission_callback( $request ) {
		$post_id = (int) $request['post_id'];
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'newspack_rest_forbidden',
				esc_html__( 'You cannot add a prompt to this post.', 'newspack-popups' ),
				[ 'status' => 403 ]
			);
		}

		// When the request targets an existing prompt, authorize against the article
		// that prompt actually belongs to — not the post_id the caller supplied.
		// Otherwise anyone who can edit a single post of their own could pass another
		// story's prompt_id and rewrite its copy or repoint its donate URL.
		$prompt_id = (int) ( $request['prompt_id'] ?? 0 );
		if ( $prompt_id ) {
			$parent_id = Newspack_Popups_Post_Scope::get_scoped_post_id( [ 'id' => $prompt_id ] );
			if ( ! $parent_id || ! current_user_can( 'edit_post', $parent_id ) ) {
				return new \WP_Error(
					'newspack_rest_forbidden',
					esc_html__( 'You cannot edit this prompt.', 'newspack-popups' ),
					[ 'status' => 403 ]
				);
			}
		}

		return true;
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
	 * manage it, and the publisher-profile fields.
	 *
	 * @return array
	 */
	private static function contextual_prompt_status() {
		return [
			'enabled'         => Newspack_Popups_Settings::is_ai_copy_assistant_enabled(),
			'can_manage'      => current_user_can( 'manage_options' ),
			'fields'          => Newspack_Popups_Settings::get_ai_copy_assistant_fields(),
			'override_active' => Newspack_Popups_Settings::is_override_active(),
		];
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
	 * Get the Contextual Prompt scoped to a post (for editing), or null.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function api_get_scoped_prompt( $request ) {
		$enabled = self::require_contextual_prompts_enabled();
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}

		return rest_ensure_response(
			[
				'prompt'          => Newspack_Popups_Post_Scope::get_scoped_prompt_for_post( $request['post_id'] ),
				'override_active' => Newspack_Popups_Settings::is_override_active(),
			]
		);
	}

	/**
	 * Create a Contextual Prompt scoped to a post, or update the existing one
	 * when a prompt_id is supplied.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function api_create_contextual_prompt( $request ) {
		$enabled = self::require_contextual_prompts_enabled();
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}

		$prompt_id = (int) ( $request['prompt_id'] ?? 0 );

		// Reset-design and show/hide are independent of the copy, so they run BEFORE
		// the content update rather than after it. Otherwise a prompt whose copy
		// block was removed while customizing — the one case that makes the update
		// fail — could no longer be reset or hidden either, even though the failure
		// message points the publisher at exactly those actions.
		if ( $prompt_id ) {
			if ( ! empty( $request['reset_design'] ) ) {
				$reset = Newspack_Popups_Post_Scope::reset_prompt_design( $prompt_id );
				if ( is_wp_error( $reset ) ) {
					return $reset;
				}
			}

			if ( isset( $request['enabled'] ) ) {
				$status_result = Newspack_Popups_Post_Scope::set_scoped_prompt_enabled( $prompt_id, (bool) $request['enabled'] );
				if ( is_wp_error( $status_result ) ) {
					return $status_result;
				}
			}
		}

		if ( $prompt_id ) {
			$result = Newspack_Popups_Post_Scope::update_scoped_prompt(
				$prompt_id,
				[
					'body'         => $request['body'],
					'button_label' => $request['button_label'] ?? '',
					'button_url'   => $request['button_url'] ?? '',
					'position'     => $request['position'] ?? 3,
					'ai_edited'    => $request['ai_edited'] ?? false,
				]
			);
		} else {
			$result = Newspack_Popups_Post_Scope::create_scoped_prompt(
				[
					'post_id'          => $request['post_id'],
					'body'             => $request['body'],
					'button_label'     => $request['button_label'] ?? '',
					'button_url'       => $request['button_url'] ?? '',
					'position'         => $request['position'] ?? 3,
					'template_version' => $request['template_version'] ?? '',
					'request_id'       => $request['request_id'] ?? '',
					'ai_generated'     => $request['ai_generated'] ?? false,
					'ai_edited'        => $request['ai_edited'] ?? false,
				]
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// A newly-created prompt can still carry an initial visibility choice; for an
		// existing one this was already applied above.
		if ( ! $prompt_id && isset( $request['enabled'] ) ) {
			$status_result = Newspack_Popups_Post_Scope::set_scoped_prompt_enabled( $result, (bool) $request['enabled'] );
			if ( is_wp_error( $status_result ) ) {
				return $status_result;
			}
		}

		return rest_ensure_response(
			[
				'id'         => $result,
				'edit_link'  => get_edit_post_link( $result, 'rest' ),
				'enabled'    => 'publish' === get_post_status( $result ),
				'customized' => Newspack_Popups_Post_Scope::is_customized( $result ),
			]
		);
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
