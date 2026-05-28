<?php
/**
 * Newspack Story Budget API
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget;

use Newspack_Story_Budget\Fields\Abstract_Field;

/**
 * API Class.
 */
class API {

	/**
	 * API Namespace
	 *
	 * @var string
	 */
	const NAMESPACE = 'newspack-story-budget/v1';

	/**
	 * Default limit of items to return.
	 */
	const DEFAULT_LIMIT = 1000;

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Register REST API routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/stories',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_stories' ],
				'permission_callback' => [ __CLASS__, 'stories_permission_callback' ],
				'args'                => [
					'metadata' => [
						'description' => __( 'Whether to include metadata in the response.', 'newspack-story-budget' ),
						'type'        => 'boolean',
					],
					'limit'    => [
						'description' => __( 'Number of stories to return.', 'newspack-story-budget' ),
						'type'        => 'integer',
					],
					'offset'   => [
						'description' => __( 'Offset.', 'newspack-story-budget' ),
						'type'        => 'integer',
					],
					'ids'      => [
						'description' => __( 'Array of story IDs to fetch.', 'newspack-story-budget' ),
						'type'        => 'array',
						'items'       => [
							'type' => 'integer',
						],
					],
					'since'    => [
						'description' => __( 'Date in UNIX timestamp format to fetch stories modified since this time.', 'newspack-story-budget' ),
						'type'        => 'integer',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/stories',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'create_story' ],
				'permission_callback' => [ __CLASS__, 'stories_permission_callback' ],
				'args'                => [
					'title'   => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'budgets' => [
						'type'    => 'array',
						'items'   => [
							'type' => 'integer',
						],
						'default' => [],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/stories/meta',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_stories_meta' ],
				'permission_callback' => [ __CLASS__, 'stories_permission_callback' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/stories/meta/batch',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'get_stories_meta_batch' ],
				'permission_callback' => [ __CLASS__, 'stories_permission_callback' ],
				'args'                => [
					'ids' => [
						'description' => __( 'Array of story IDs to fetch meta for.', 'newspack-story-budget' ),
						'type'        => 'array',
						'items'       => [
							'type' => 'integer',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/stories/search',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'get_stories_search' ],
				'permission_callback' => [ __CLASS__, 'stories_permission_callback' ],
				'args'                => [
					's' => [
						'description' => __( 'Search query.', 'newspack-story-budget' ),
						'type'        => 'string',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/stories/update',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'update_stories' ],
				'permission_callback' => [ __CLASS__, 'stories_permission_callback' ],
				'args'                => [
					'ids'    => [
						'description' => __( 'Array of story IDs to update.', 'newspack-story-budget' ),
						'type'        => 'array',
						'items'       => [ 'type' => 'integer' ],
					],
					'fields' => [
						'description' => __( 'Array of fields to update.', 'newspack-story-budget' ),
						'type'        => 'array',
						'items'       => [
							'type'       => 'object',
							'properties' => [
								'slug'  => [ 'type' => 'string' ],
								'value' => [ 'type' => 'mixed' ],
							],
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/stories/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_story' ],
				'permission_callback' => [ __CLASS__, 'stories_permission_callback' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/stories/(?P<id>\d+)/meta',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_story_meta' ],
				'permission_callback' => [ __CLASS__, 'stories_permission_callback' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/stories/(?P<id>\d+)',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'update_story' ],
				'permission_callback' => [ __CLASS__, 'stories_permission_callback' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/stories/(?P<id>\d+)/(?P<slug>[\a-z]+)',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'update_story_field' ],
				'permission_callback' => [ __CLASS__, 'stories_permission_callback' ],
				'args'                => [
					'id'    => [
						'description' => __( 'The post ID of the story to update.', 'newspack-story-budget' ),
						'type'        => 'integer',
					],
					'slug'  => [
						'description' => __( 'The slug of the field to update.', 'newspack-story-budget' ),
						'type'        => 'string',
					],
					'value' => [
						'description' => __( 'The value to update the field with.', 'newspack-story-budget' ),
						'type'        => 'mixed',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/fields',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_fields' ],
				'permission_callback' => [ __CLASS__, 'permission_callback' ],
				'args'                => [
					'fields' => [
						'description' => __( 'Array of field slugs to return. If not provided, all fields will be returned.', 'newspack-story-budget' ),
						'type'        => 'array',
					],
				],
			]
		);

		// @TODO Add more routes for budget CRUD.

		register_rest_route(
			self::NAMESPACE,
			'/budgets/(?P<id>\d+)',
			[
				'methods'             => 'PUT',
				'callback'            => [ __CLASS__, 'update_budget' ],
				'permission_callback' => [ __CLASS__, 'permission_callback' ],
				'args'                => [
					'id'       => [
						'description' => __( 'The ID of the budget to update.', 'newspack-story-budget' ),
						'type'        => 'integer',
					],
					'name'     => [
						'description' => __( 'The name of the budget.', 'newspack-story-budget' ),
						'type'        => 'string',
					],
					'archived' => [
						'description' => __( 'Whether the budget is archived.', 'newspack-story-budget' ),
						'type'        => 'boolean',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/budgets',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_budgets' ],
				'permission_callback' => [ __CLASS__, 'permission_callback' ],
				'args'                => [
					'limit'            => [
						'description' => __( 'Number of budgets to return.', 'newspack-story-budget' ),
						'type'        => 'integer',
						'default'     => self::DEFAULT_LIMIT,
					],
					'offset'           => [
						'description' => __( 'Offset.', 'newspack-story-budget' ),
						'type'        => 'integer',
						'default'     => 0,
					],
					'include_archived' => [
						'description' => __( 'Whether to include archived budgets.', 'newspack-story-budget' ),
						'type'        => 'boolean',
						'default'     => true,
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/budgets/search',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'get_budgets_search' ],
				'permission_callback' => [ __CLASS__, 'permission_callback' ],
				'args'                => [
					's' => [
						'description' => __( 'Search query.', 'newspack-story-budget' ),
						'type'        => 'string',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/budgets/order',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'set_active_budget_order' ],
				'permission_callback' => [ __CLASS__, 'permission_callback' ],
				'args'                => [
					'ids' => [
						'description' => __( 'Array of budget IDs to set as active.', 'newspack-story-budget' ),
						'type'        => 'array',
						'items'       => [
							'type' => 'integer',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/budgets',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'create_budget' ],
				'permission_callback' => [ __CLASS__, 'permission_callback' ],
				'args'                => [
					'name' => [
						'description' => __( 'Name of the budget.', 'newspack-story-budget' ),
						'type'        => 'string',
					],
				],
			]
		);
		// @TODO Add more routes for budget CRUD.

		register_rest_route(
			self::NAMESPACE,
			'/budgets/(?P<id>\d+)/stories',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_budget_stories' ],
				'permission_callback' => [ __CLASS__, 'permission_callback' ],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			'/budgets/(?P<id>\d+)/stories/search',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'get_budget_stories_search' ],
				'permission_callback' => [ __CLASS__, 'permission_callback' ],
				'args'                => [
					's' => [
						'description' => __( 'Search query.', 'newspack-story-budget' ),
						'type'        => 'string',
					],
				],
			]
		);
	}

	/**
	 * Permission callback for non-story entities.
	 *
	 * @return bool
	 */
	public static function permission_callback() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Permission callback for stories.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return bool
	 */
	public static function stories_permission_callback( $request ) {
		if ( current_user_can( 'edit_others_posts' ) ) {
			return true;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}

		$id = $request->get_param( 'id' );
		if ( $id ) {
			$post = get_post( $id );
			if ( (int) $post->post_author !== (int) get_current_user_id() ) {
				return false;
			}
			$method = $request->get_method();
			if ( 'GET' === $method ) {
				return current_user_can( 'read_post', $id );
			}
			return current_user_can( 'edit_post', $id );
		}

		return true;
	}

	/**
	 * Get stories.
	 *
	 * @param \WP_Rest_Request $request Request object.
	 *
	 * @return \WP_Rest_Response
	 */
	public static function get_stories( $request ) {
		$query_args = [
			'posts_per_page' => $request->get_param( 'limit' ) ?? self::DEFAULT_LIMIT,
			'offset'         => $request->get_param( 'offset' ) ?? 0,
		];

		// If fetching specific stories by ID.
		if ( $request->get_param( 'ids' ) ) {
			// Validate story IDs.
			$story_ids                    = array_values(
				array_filter(
					$request->get_param( 'ids' ),
					function( $id ) {
						$story = new Story( $id );
						return $story->is_valid();
					}
				)
			);
			$query_args['post__in']       = $story_ids;
			$query_args['posts_per_page'] = count( $query_args['post__in'] );
			$query_args['offset']         = 0;
		}

		// If fetching stories modified since a certain timestamp.
		if ( $request->get_param( 'since' ) ) {
			$query_args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'     => Abstract_Field::FIELD_PREFIX . '_modified',
					'value'   => $request->get_param( 'since' ),
					'compare' => '>=',
				],
			];
		}

		// If the user is not an editor, filter the stories by the user's stories.
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$query_args['author'] = get_current_user_id();
		}

		$stories          = Budgets::get_stories( $query_args );
		$include_metadata = boolval( $request->get_param( 'metadata' ) );

		return rest_ensure_response(
			[
				'stories' => array_map(
					function( $story ) use ( $include_metadata ) {
						// If fetching specific stories by ID or modified since a certain timestamp, include metadata.
						return $story->to_array( $include_metadata );
					},
					$stories
				),
				'total'   => Budgets::$stories_query->found_posts,
			]
		);
	}

	/**
	 * Callback for creating a story.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Rest response or error.
	 */
	public static function create_story( $request ) {
		$fields     = $request->get_params();
		$title      = $fields['title'] ?? '';

		if ( empty( $title ) ) {
			return new \WP_Error(
				'invalid_story_title',
				__( 'Story title cannot be empty.', 'newspack-story-budget' ),
				array( 'status' => 400 )
			);
		}

		if ( isset( $fields['budgets'] ) && ( empty( $fields['budgets'] ) || ! is_array( $fields['budgets'] ) ) ) {
			return new \WP_Error(
				'invalid_story_budget',
				__( 'At least one budget must be selected.', 'newspack-story-budget' ),
				array( 'status' => 400 )
			);
		}

		$post_data = array(
			'post_title'  => $title,
			'post_status' => 'draft',
			'post_type'   => 'post',
		);

		$post_id = wp_insert_post( $post_data );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$story = new Story( $post_id );

		$excluded_keys = [ 'title', 'newBudgetName', '_locale' ];
		$custom_fields = array_diff_key( $fields, array_flip( $excluded_keys ) );
		$set_fields    = $story->update( $custom_fields );

		if ( is_wp_error( $set_fields ) ) {
			return $set_fields;
		}

		return rest_ensure_response( $story->to_array() );
	}

	/**
	 * Get stories meta.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_stories_meta( $request ) {
		return rest_ensure_response(
			[
				'can_edit' => current_user_can( 'edit_others_posts' ),
			]
		);
	}

	/**
	 * Get stories meta batch.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_stories_meta_batch( $request ) {
		$story_ids = $request->get_param( 'ids' );

		$is_editor = current_user_can( 'edit_others_posts' );

		$results = [];
		foreach ( $story_ids as $story_id ) {
			$story = new Story( $story_id );
			if ( ! $story->is_valid() ) {
				$results[ $story_id ] = new \WP_Error( 'invalid_story', __( 'Invalid story.', 'newspack-story-budget' ) );
				continue;
			}
			if ( ! $is_editor && (int) $story->post->post_author !== (int) get_current_user_id() ) {
				continue;
			}
			$results[ $story_id ] = $story->get_metadata();
		}

		return rest_ensure_response( $results );
	}

	/**
	 * Get stories search.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_stories_search( $request ) {
		$query_args = [
			'story_budget_search' => true,
			'fields'              => 'ids',
			'posts_per_page'      => -1,
			's'                   => $request->get_param( 's' ) ?? '',
		];

		// If the user is not an editor, filter the stories by the user's stories.
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$query_args['author'] = get_current_user_id();
		}

		return rest_ensure_response(
			[
				'story_ids' => Budgets::get_stories( $query_args ),
				'total'     => Budgets::$stories_query->found_posts,
			]
		);
	}

	/**
	 * Update one or multiple stories.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response Updated stories.
	 */
	public static function update_stories( $request ) {
		$story_ids = $request->get_param( 'ids' );

		$fields = [];
		foreach ( $request->get_param( 'fields' ) as $field ) {
			$fields[ $field['slug'] ] = $field['value'];
		}

		$stories = [];
		foreach ( $story_ids as $story_id ) {
			$story = new Story( $story_id );
			if ( ! $story->is_valid() ) {
				continue;
			}
			if ( ! current_user_can( 'edit_post', $story_id ) ) {
				continue;
			}
			$result = $story->update( $fields );
			if ( ! \is_wp_error( $result ) ) {
				$stories[] = $story->to_array();
			}
		}

		return rest_ensure_response(
			[
				'stories' => $stories,
			]
		);
	}

	/**
	 * Get story.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_story( $request ) {
		$story = new Story( $request->get_param( 'id' ) );
		if ( ! $story->is_valid() ) {
			return new \WP_Error( 'story_not_found', __( 'Story not found.', 'newspack-story-budget' ), [ 'status' => 404 ] );
		}

		// Refresh read-only field values.
		$story->refresh();

		return rest_ensure_response( $story->to_array() );
	}

	/**
	 * Get story meta.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_story_meta( $request ) {
		$story = new Story( $request->get_param( 'id' ) );
		if ( ! $story->is_valid() ) {
			return new \WP_Error( 'story_not_found', __( 'Story not found.', 'newspack-story-budget' ), [ 'status' => 404 ] );
		}

		return rest_ensure_response( $story->get_metadata() );
	}

	/**
	 * Sanitize a value based on the field's type.
	 *
	 * @param \Newspack_Story_Budget\Fields\Abstract_Field $field The field to validate against.
	 * @param mixed                                        $value The value to validate.
	 *
	 * @return mixed The sanitized value, or null if the value can't be sanitized to the field's expected type.
	 */
	private static function sanitize_field_value( $field, $value ) {
		$type = $field->get_type();
		if ( is_array( $value ) ) {
			return array_values(
				array_filter(
					array_map(
						function( $single_value ) use ( $field ) {
							return self::sanitize_field_value( $field, $single_value );
						},
						$value
					),
					function( $value ) {
						return ! is_null( $value );
					}
				)
			);
		}
		if ( 'boolean' === $type ) {
			return \rest_sanitize_boolean( $value );
		}
		if ( 'number' === $type ) {
			return is_numeric( $value ) ? (float) $value : null;
		}
		if ( 'text' === $type || 'longtext' === $type ) {
			return \sanitize_text_field( $value );
		}

		// Date values are stored as UNIX timestamps.
		if ( 'date' === $type || 'datetime' === $type ) {
			if ( (int) $value === (int) (string) $value && (int) $value <= PHP_INT_MAX && (int) $value >= ~PHP_INT_MAX ) {
				return (int) $value;
			}
		}
		return null;
	}

	/**
	 * Update a story.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_story( $request ) {
		$story = new Story( $request->get_param( 'id' ) );
		if ( ! $story->is_valid() ) {
			return new \WP_Error(
				'story_not_found',
				sprintf(
					// translators: %d is the story ID.
					__( 'Story with ID "%d" not found.', 'newspack-story-budget' ),
					$request->get_param( 'id' )
				),
				[ 'status' => 404 ]
			);
		}
		$params  = $request->get_params();
		$payload = [];
		foreach ( $params as $key => $value ) {
			$field = Fields::get_field( $key );
			if ( ! $field || ! $field->is_editable() ) {
				continue;
			}
			$value = self::sanitize_field_value( $field, $value );
			if ( null === $value && null !== $request->get_param( $key ) ) {
				return new \WP_Error(
					'invalid_value',
					sprintf(
						// Translators: field data type.
						__( 'Invalid value for field type "%s".', 'newspack-story-budget' ),
						$field->get_type()
					)
				);
			}
			$payload[ $key ] = $value;
		}
		$result = $story->update( $payload );
		return rest_ensure_response( \is_wp_error( $result ) ? $result : $story->to_array() );
	}

	/**
	 * Update a story field.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_story_field( $request ) {
		$story = new Story( $request->get_param( 'id' ) );
		$field  = $request->get_param( 'slug' ) ? Fields::get_field( $request->get_param( 'slug' ) ) : null;
		$value = self::sanitize_field_value( $field, $request->get_param( 'value' ) );
		if ( ! $story->is_valid() ) {
			return new \WP_Error(
				'story_not_found',
				sprintf(
					// translators: %d is the story ID.
					__( 'Story with ID "%d" not found.', 'newspack-story-budget' ),
					$request->get_param( 'id' )
				)
			);
		}
		if ( empty( $field ) ) {
			return new \WP_Error(
				'missing_field',
				__( 'Missing field.', 'newspack-story-budget' )
			);
		}
		if ( null === $value && null !== $request->get_param( 'value' ) ) {
			return new \WP_Error(
				'invalid_value',
				sprintf(
					// Translators: field data type.
					__( 'Invalid value for field type "%s".', 'newspack-story-budget' ),
					$field->get_type()
				)
			);
		}

		$result = $story->update( [ $field->get_slug() => $value ] );
		return rest_ensure_response( \is_wp_error( $result ) ? $result : $story->to_array() );
	}

	/**
	 * Get story fields.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_fields( $request ) {
		$fields_to_get = $request->get_param( 'fields' ) ?? [];
		$fields = Fields::get_all_fields( true );
		if ( ! empty( $fields_to_get ) ) {
			$fields = array_values(
				array_filter(
					$fields,
					function( $field ) use ( $fields_to_get ) {
						return in_array( $field['slug'], $fields_to_get );
					}
				)
			);
		}
		return rest_ensure_response( array_values( $fields ) );
	}

	/**
	 * Get budgets search.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_budgets( $request ) {
		$limit            = $request->get_param( 'limit' ) ?? self::DEFAULT_LIMIT;
		$offset           = $request->get_param( 'offset' ) ?? 0;
		$include_archived = $request->get_param( 'include_archived' ) ?? true;

		$budgets = array_map(
			function( $budget ) {
				return $budget->to_array();
			},
			Budgets::get_budgets( $include_archived )
		);
		$total   = count( $budgets );

		// Limit and offset.
		if ( $limit < count( $budgets ) ) {
			$budgets = array_slice( $budgets, $offset, $limit );
		}

		return rest_ensure_response(
			[
				'budgets' => $budgets,
				'total'   => $total,
			]
		);
	}

	/**
	 * Callback for creating a budget.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Rest response or error.
	 */
	public static function create_budget( $request ) {
		$name = $request->get_param( 'name' );

		if ( empty( $name ) ) {
			return new \WP_Error(
				'invalid_budget_name',
				__( 'Budget name cannot be empty.', 'newspack-story-budget' ),
				array( 'status' => 400 )
			);
		}

		$term = wp_insert_term( $name, 'newspack_story_budget' );

		if ( is_wp_error( $term ) ) {
			return $term;
		}

		$budget = new Budget( $term['term_id'] );

		return rest_ensure_response( $budget->to_array() );
	}

	/**
	 * Get budget stories.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_budget_stories( $request ) {
		$budget = new Budget( $request->get_param( 'id' ) );
		if ( ! $budget->is_valid() ) {
			return new \WP_Error( 'budget_not_found', __( 'Budget not found.', 'newspack-story-budget' ), [ 'status' => 404 ] );
		}

		$query_args = [];

		// If the user is not an editor, filter the stories by the user's stories.
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$query_args['author'] = get_current_user_id();
		}

		$stories = $budget->get_stories( $query_args );

		return rest_ensure_response(
			[
				'stories' => array_map(
					function( $story ) {
						return $story->to_array();
					},
					$stories
				),
				'total'   => Budgets::$stories_query->found_posts,
			]
		);
	}

	/**
	 * Get budget stories search.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_budget_stories_search( $request ) {
		$budget = new Budget( $request->get_param( 'id' ) );
		if ( ! $budget->is_valid() ) {
			return new \WP_Error( 'budget_not_found', __( 'Budget not found.', 'newspack-story-budget' ), [ 'status' => 404 ] );
		}

		$query_args = [
			'story_budget_search' => true,
			'fields'              => 'ids',
			'posts_per_page'      => -1,
			's'                   => $request->get_param( 's' ) ?? '',
		];

		// If the user is not an editor, filter the stories by the user's stories.
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$query_args['author'] = get_current_user_id();
		}

		return rest_ensure_response(
			[
				'story_ids' => $budget->get_stories( $query_args ),
				'total'     => Budgets::$stories_query->found_posts,
			]
		);
	}

	/**
	 * Get budgets search.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_budgets_search( $request ) {
		$query_args = [
			'fields'     => 'ids',
			'search'     => $request->get_param( 's' ) ?? '',
			'taxonomy'   => Budgets::TAXONOMY,
			'hide_empty' => false,
		];

		$search_results = get_terms( $query_args );

		return rest_ensure_response(
			[
				'budget_ids' => $search_results,
			]
		);
	}

	/**
	 * Update a budget.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_budget( $request ) {
		$budget = new Budget( $request->get_param( 'id' ) );
		if ( ! $budget->is_valid() ) {
			return new \WP_Error(
				'budget_not_found',
				sprintf(
					// translators: %d is the budget ID.
					__( 'Budget with ID "%d" not found.', 'newspack-story-budget' ),
					$request->get_param( 'id' )
				),
				[ 'status' => 404 ]
			);
		}

		$params = $request->get_params();
		foreach ( $params as $key => $value ) {
			if ( 'name' === $key && ! empty( $value ) && $value !== $budget->term->name ) {
				$updated_name = sanitize_text_field( $value );
				$updated_slug = sanitize_title( $updated_name );

				$existing_term = term_exists( 'slug', $updated_slug, Budgets::TAXONOMY );
				if ( $existing_term && $existing_term->term_id !== $budget->term->term_id ) {
					$updated_slug = wp_unique_term_slug( $updated_slug, $budget->term );
				}

				wp_update_term(
					$budget->term->term_id,
					Budgets::TAXONOMY,
					[
						'name' => $updated_name,
						'slug' => $updated_slug,
					]
				);
				$budget->term->name = $updated_name;
				$budget->term->slug = $updated_slug;
			}
			if ( 'archived' === $key && $value !== $budget->archived ) {
				if ( $value ) {
					$budget->archive();
				} else {
					$budget->unarchive();
				}
			}
			if ( 'archive_at' === $key ) {
				if ( empty( $value ) ) {
					$budget->clear_auto_archive();
				} else {
					$budget->set_auto_archive( $value );
				}
			}
		}

		return rest_ensure_response(
			$budget->to_array()
		);
	}

	/**
	 * Set active budgets.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public static function set_active_budget_order( $request ) {
		$budget_ids = $request->get_param( 'ids' );

		// Loop through the corresponding budgets and set their order.
		Budgets::update_budgets_order( $budget_ids );

		return rest_ensure_response( [ 'active_budget_order' => $budget_ids ] );
	}
}
