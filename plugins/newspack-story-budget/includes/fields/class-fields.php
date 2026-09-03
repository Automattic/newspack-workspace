<?php
/**
 * Newspack Story Budget - class for handling fields.
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget;

use Newspack_Story_Budget\Fields\Abstract_Field;
use Newspack_Story_Budget\Fields\Editable_Field;
use Newspack_Story_Budget\Fields\Read_Only_Field;
use Newspack_Story_Budget\Fields\Taxonomy_Field;
use Newspack_Story_Budget\Fields\Statuses;

/**
 * Story budget fields.
 */
class Fields {
	/**
	 * Registered fields.
	 *
	 * @var array
	 */
	protected static $all_fields = [];

	/**
	 * Initializes default fields.
	 */
	public static function init() {
		\add_action( 'init', [ __CLASS__, 'register_fields' ] );
		\add_action( 'set_object_terms', [ __CLASS__, 'on_post_budget_update' ], 10, 2 );
		\add_action( 'save_post', [ __CLASS__, 'on_post_update' ] );

		// Add custom columns for fields that should be displayed in the admin list table.
		\add_filter( 'manage_post_posts_columns', [ __CLASS__, 'wp_posts_columns' ] );
		\add_action( 'manage_post_posts_custom_column', [ __CLASS__, 'wp_posts_columns_values' ], 10, 2 );
	}

	/**
	 * Register fields.
	 */
	public static function register_fields() {
		$default_fields_config  = self::get_default_fields_config();
		$protected_field_slugs = [ 'id', 'metadata' ];

		/**
		 * Filters the story budget fields to register.
		 */
		$field_configs = apply_filters( 'newspack_story_budget_fields', array_merge( $default_fields_config, [] ) );

		foreach ( $field_configs as $field_config ) {
			if ( ! empty( $field_config['is_editable'] ) ) {
				$field = new Editable_Field( $field_config );
			} elseif ( ! empty( $field_config['taxonomy'] ) ) {
				$field = new Taxonomy_Field( $field_config );
			} else {
				$field = new Read_Only_Field( $field_config );
			}

			if ( isset( self::$all_fields[ $field->get_slug() ] ) ) {
				Logger::error( sprintf( 'Field with slug `%s` already exists.', $field->get_slug() ) );
				continue;
			}

			if ( in_array( $field->get_slug(), $protected_field_slugs, true ) ) {
				Logger::error( sprintf( 'The slug `%s` is protected. Please choose a different slug.', $field->get_slug() ) );
				continue;
			}

			// Don't register the field if creating it threw any errors.
			if ( $field->has_errors() ) {
				$field_errors = $field->get_errors();
				Logger::error(
					array_merge(
						[
							sprintf(
								// Translators: %s is the field slug.
								__( 'Encountered errors registering field: %s.', 'newspack-story-budget' ),
								$field->get_slug()
							),
						],
						$field_errors->get_error_messages()
					)
				);
				continue;
			}
			self::$all_fields[ $field->get_slug() ] = $field;
		}
	}

	/**
	 * Get config for default fields.
	 *
	 * @return array
	 */
	public static function get_default_fields_config() {
		return [
			[
				'default_value'           => [ __CLASS__, 'get_default_story_name' ],
				'description'             => __( 'An internal name for the story.', 'newspack-story-budget' ),
				'is_editable'             => true,
				'is_searchable'           => true,
				'is_sortable'             => true,
				'show_in_wp_posts_table'  => true,
				'name'                    => __( 'Story Name', 'newspack-story-budget' ),
				'show_in_table'           => true,
				'always_visible_in_table' => true,
				'show_in_editor'          => true,
				'slug'                    => 'name',
				'type'                    => 'text',
				'default_order'           => 0,
			],
			[
				'description'             => __( 'The post title.', 'newspack-story-budget' ),
				'get_value_callback'      => [ __CLASS__, 'get_title' ],
				'is_editable'             => false,
				'is_sortable'             => true,
				'name'                    => __( 'Title', 'newspack-story-budget' ),
				'slug'                    => 'title',
				'type'                    => 'text',
				'default_order'           => 12,
				'always_visible_in_table' => true,
			],
			[
				'description'             => __( 'An internal description for the story.', 'newspack-story-budget' ),
				'is_editable'             => true,
				'is_searchable'           => true,
				'is_sortable'             => true,
				'name'                    => __( 'Description', 'newspack-story-budget' ),
				'show_in_editor'          => true,
				'slug'                    => 'description',
				'type'                    => 'longtext',
				'default_order'           => 6,
				'show_in_table'           => true,
				'always_visible_in_table' => true,
			],
			[
				'description'             => Budgets::is_multiple_budgets_enabled() ? __( 'Story budgets this story is assigned to.', 'newspack-story-budget' ) : __( 'Story budget this story is assigned to.', 'newspack-story-budget' ),
				'get_value_callback'      => [ __CLASS__, 'get_budgets' ],
				'is_editable'             => true,
				'is_filterable'           => 'always',

				/**
				 * Filters whether a story can be assigned to multiple budgets.
				 *
				 * @param bool $multiple_budgets_enabled Whether a story can be assigned to multiple budgets.
				 */
				'is_multiple'             => Budgets::is_multiple_budgets_enabled(),
				'name'                    => Budgets::is_multiple_budgets_enabled() ? __( 'Budgets', 'newspack-story-budget' ) : __( 'Budget', 'newspack-story-budget' ),
				'save_value_callback'     => [ __CLASS__, 'save_budgets' ],
				'show_in_table'           => true,
				'always_visible_in_table' => true,
				'show_in_editor'          => true,
				'slug'                    => 'budgets',
				'type'                    => 'number',
				'options'                 => array_map(
					function( $budget ) {
						$budget = $budget->to_array();
						return [
							'label' => $budget['name'],
							'value' => $budget['id'],
						];
					},
					Budgets::get_budgets()
				),
				'default_order'           => 2,
			],
			[
				'default_value'           => function() {
					return 'writing';
				},
				'description'             => __( 'The current editorial status of the story.', 'newspack-story-budget' ),
				'is_editable'             => true,
				'is_filterable'           => 'always',
				'is_sortable'             => false,
				'name'                    => __( 'Status', 'newspack-story-budget' ),
				'show_in_table'           => true,
				'always_visible_in_table' => true,
				'show_in_editor'          => true,
				'slug'                    => 'status',
				'type'                    => 'text',
				'options'                 => Statuses::get_statuses_arrays(),
				'save_value_callback'     => [ __CLASS__, 'save_post_status' ],
				'get_value_callback'      => [ __CLASS__, 'get_post_status' ],
				'default_order'           => 5,
			],
			[
				'description'             => __( 'The word count of the story.', 'newspack-story-budget' ),
				'is_editable'             => false,
				'is_sortable'             => true,
				'name'                    => __( 'Length', 'newspack-story-budget' ),
				'post_save_callback'      => [ __CLASS__, 'get_word_count' ],
				'show_in_table'           => true,
				'slug'                    => 'word_count',
				'type'                    => 'number',
				'default_order'           => 7,
				'always_visible_in_table' => true,
			],
			[
				'description'             => __( 'Number of images in story content, not including the featured image.', 'newspack-story-budget' ),
				'is_editable'             => false,
				'is_sortable'             => true,
				'name'                    => __( 'Images', 'newspack-story-budget' ),
				'post_save_callback'      => [ __CLASS__, 'get_image_count' ],
				'show_in_table'           => true,
				'slug'                    => 'image_count',
				'type'                    => 'number',
				'default_order'           => 8,
				'always_visible_in_table' => true,
			],
			[
				'description'        => __( 'Time of last modification.', 'newspack-story-budget' ),
				'get_value_callback' => [ __CLASS__, 'get_modified_time' ],
				'is_editable'        => false,
				'is_sortable'        => true,
				'name'               => __( 'Last modified', 'newspack-story-budget' ),
				'show_in_table'      => true,
				'slug'               => 'last_modified',
				'type'               => 'datetime',
				'default_order'      => 13,
			],
			[
				'description'        => __( 'The date the story was published online.', 'newspack-story-budget' ),
				'get_value_callback' => [ __CLASS__, 'get_publish_time' ],
				'is_editable'        => false,
				'is_sortable'        => true,
				'name'               => __( 'Publish date', 'newspack-story-budget' ),
				'show_in_table'      => true,
				'slug'               => 'publish_date',
				'type'               => 'date',
				'default_order'      => 14,
			],
			[
				'description'        => __( 'The user who published the story online.', 'newspack-story-budget' ),
				'is_editable'        => false,
				'is_sortable'        => true,
				'name'               => __( 'Published by', 'newspack-story-budget' ),
				'post_save_callback' => [ __CLASS__, 'get_published_user' ],
				'slug'               => 'published_by',
				'type'               => 'text',
				'default_order'      => 15,
			],
			[

				'description'             => __( 'Authors assigned to the post.', 'newspack-story-budget' ),
				'get_value_callback'      => [ __CLASS__, 'get_authors' ],
				'is_editable'             => false,
				'is_multiple'             => true,
				'is_filterable'           => 'always',
				'name'                    => __( 'Authors', 'newspack-story-budget' ),
				'show_in_table'           => true,
				'slug'                    => 'authors',
				'type'                    => 'text',
				'default_order'           => 3,
				'always_visible_in_table' => true,
			],
			[
				'description'   => __( 'Categories assigned to the post.', 'newspack-story-budget' ),
				'taxonomy'      => 'category',
				'is_filterable' => 'always',
				'name'          => __( 'Categories', 'newspack-story-budget' ),
				'slug'          => 'categories',
				'default_order' => 16,
			],
			[
				'description'             => __( 'Whether the story is currently being edited by another user.', 'newspack-story-budget' ),
				'get_value_callback'      => [ __CLASS__, 'is_post_locked' ],
				'is_editable'             => false,
				'is_filterable'           => 'yes',
				'name'                    => __( 'Locked', 'newspack-story-budget' ),
				'show_in_table'           => true,
				'slug'                    => 'is_locked',
				'type'                    => 'boolean',
				'default_order'           => 4,
				'always_visible_in_table' => true,
			],
			[
				'description'             => __( 'The status of the post in WordPress.', 'newspack-story-budget' ),
				'get_value_callback'      => [ __CLASS__, 'get_post_wp_status' ],
				'is_editable'             => false,
				'is_filterable'           => 'always',
				'name'                    => __( 'WP Status', 'newspack-story-budget' ),
				'show_in_table'           => true,
				'slug'                    => 'wp_status',
				'type'                    => 'text',
				'default_order'           => 9,
				'always_visible_in_table' => true,
			],

		];
	}

	/**
	 * Get all registered fields.
	 *
	 * @param bool $as_array Whether to return the field as an array.
	 *
	 * @return \Newspack_Story_Budget\Fields\Abstract_Field|array[] Array of field objects or info.
	 */
	public static function get_all_fields( $as_array = false ) {
		// Sort fields by default_order.
		$sorted_fields = array_values( self::$all_fields );
		uasort(
			$sorted_fields,
			function( $a, $b ) {
				return $a->get_default_order() - $b->get_default_order();
			}
		);

		if ( $as_array ) {
			return array_map(
				function( $field ) {
					return $field->to_array();
				},
				$sorted_fields
			);
		}
		return $sorted_fields;
	}

	/**
	 * Get a field by its slug.
	 *
	 * @param string $slug The slug for the field to get.
	 * @param bool   $as_array Whether to return the field as an array.
	 *
	 * @return \Newspack_Story_Budget\Fields\Abstract_Field|array The field object or info.
	 */
	public static function get_field( $slug, $as_array = false ) {
		if ( ! isset( self::$all_fields[ $slug ] ) ) {
			return null;
		}
		return $as_array ? self::$all_fields[ $slug ]->to_array() : self::$all_fields[ $slug ];
	}

	/**
	 * Get a field by its post meta name.
	 *
	 * @param string $post_meta_name The post meta name for the field to get.
	 */
	public static function get_field_by_post_meta_name( $post_meta_name ) {
		$slug = str_replace( Abstract_Field::FIELD_PREFIX, '', $post_meta_name );
		return self::get_field( $slug );
	}

	/**
	 * When a story gets added to a budget term, update the story's stored field values.
	 *
	 * @param int   $post_id The post ID being updated.
	 * @param int[] $term_ids The term IDs being added to the post.
	 */
	public static function on_post_budget_update( $post_id, $term_ids ) {
		$story = new Story( $post_id );
		if ( ! $story->is_valid() ) {
			return;
		}
		$should_update_fields = false;
		foreach ( $term_ids as $term_id ) {
			$budget = new Budget( $term_id );
			if ( $budget->is_valid() ) {
				$should_update_fields = true;
				break;
			}
		}
		if ( $should_update_fields ) {
			self::on_post_update( $post_id );
		}
	}

	/**
	 * Update stored field value of read-only fields when post is updated.
	 *
	 * @param int $post_id The post ID being updated.
	 */
	public static function on_post_update( $post_id ) {
		$story = new Story( $post_id );
		if ( ! $story->is_valid() ) {
			return;
		}
		self::update_read_only_fields( $post_id );
		self::update_modified( $post_id );
	}

	/**
	 * Update read-only fields for the story.
	 *
	 * @param int $post_id The post ID.
	 */
	public static function update_read_only_fields( $post_id ) {
		$story = new Story( $post_id );
		if ( ! $story->is_valid() ) {
			return;
		}
		$fields = self::get_all_fields();
		foreach ( $fields as $field ) {
			if ( $field->is_editable() || ! $field->get_post_save_callback() ) {
				continue;
			}
			$value = call_user_func( $field->get_post_save_callback(), $post_id );
			if ( null !== $value ) {
				\update_post_meta( $post_id, $field->get_post_meta_name(), $value );
			} else {
				\delete_post_meta( $post_id, $field->get_post_meta_name() );
			}
		}
	}

	/**
	 * Update the modified timestamp for the post.
	 * This is needed because wp_post_update by itself doesn't update the modified timestamp.
	 *
	 * @param int $post_id The post ID to update the modified timestamp for.
	 */
	public static function update_modified( $post_id ) {
		\update_post_meta( $post_id, Abstract_Field::FIELD_PREFIX . '_modified', time() );
	}

	/**
	 * Get the default value for story name.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return string The default story name.
	 */
	public static function get_default_story_name( $post_id ) {
		return sprintf(
			// Translators: the post ID.
			__( 'Story #%d', 'newspack-story-budget' ),
			$post_id
		);
	}

	/**
	 * Get the post title.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return string The post title.
	 */
	public static function get_title( $post_id ) {
		return \get_the_title( $post_id );
	}

	/**
	 * Get budgets assigned to the post.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return int|int[]|null Budget ID, array of budget IDs, or null.
	 */
	public static function get_budgets( $post_id ) {
		$multiple_budgets_enabled = Budgets::is_multiple_budgets_enabled();
		$default_budgets          = $multiple_budgets_enabled ? [] : null;
		if ( ! $post_id ) {
			return $default_budgets;
		}
		$story = new Story( $post_id );
		if ( ! $story->is_valid() ) {
			return $default_budgets;
		}
		$budgets = $story->get_budgets();
		if ( empty( $budgets ) ) {
			return $default_budgets;
		}
		return $multiple_budgets_enabled ? $budgets : $budgets[0];
	}

	/**
	 * Update budgets assigned to the post.
	 *
	 * @param int   $post_id The post ID.
	 * @param int[] $budget_ids Budget IDs to assign to the post.
	 */
	public static function save_budgets( $post_id, $budget_ids ) {
		if ( ! $post_id ) {
			return;
		}
		$story = new Story( $post_id );
		if ( ! $story->is_valid() ) {
			return;
		}
		return $story->update_budgets( $budget_ids );
	}

	/**
	 * Get the status of the post.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return string The status of the post.
	 */
	public static function get_post_status( $post_id ) {
		$post = \get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, Budgets::get_post_types(), true ) ) {
			return '';
		}
		$status = Statuses::get_post_status( $post_id );
		if ( ! $status ) {
			return '';
		}
		return $status->get_slug();
	}

	/**
	 * Save the status of the post.
	 *
	 * @param int    $post_id The post ID.
	 * @param string $status_slug The status slug to assign to the post.
	 */
	public static function save_post_status( $post_id, $status_slug ) {
		$post = \get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, Budgets::get_post_types(), true ) ) {
			return;
		}
		return Statuses::set_post_status( $post_id, $status_slug );
	}
	/**
	 * Add custom columns to the post list table.
	 *
	 * @param array $columns The existing columns.
	 * @return array The modified columns.
	 */
	public static function wp_posts_columns( $columns ) {
		$fields = self::get_all_fields();
		foreach ( $fields as $field ) {
			if ( $field->show_in_wp_posts_table() ) {
				$columns[ $field->get_post_meta_name() ] = $field->get_name();
			}
		}
		return $columns;
	}

	/**
	 * Display the value of the custom columns in the post list table.
	 *
	 * @param string $column_name The name of the column.
	 * @param int    $post_id The post ID.
	 */
	public static function wp_posts_columns_values( $column_name, $post_id ) {
		$field = self::get_field_by_post_meta_name( $column_name );
		if ( ! $field || ! $field->show_in_wp_posts_table() ) {
			return;
		}
		$value = $field->get_value( $post_id );

		if ( ! empty( $value ) ) {
			echo esc_html( $value );
		}
	}

	/**
	 * Get the word count of the post's content.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return int The word count.
	 */
	public static function get_word_count( $post_id = null ) {
		if ( ! $post_id ) {
			$post_id = \get_the_ID();
		}
		$story = new Story( $post_id );
		if ( ! $story->is_valid() ) {
			return 0;
		}
		return str_word_count( trim( \wp_strip_all_tags( \get_post_field( 'post_content', $post_id ) ) ) );
	}

	/**
	 * Get the image count of the post's content, not including the featured image.
	 * Searches for all instances of <img> tags in the post content, which should
	 * capture Image blocks as well as images embedded via other means such as galleries.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return int The image count.
	 */
	public static function get_image_count( $post_id = null ) {
		if ( ! $post_id ) {
			$post_id = \get_the_ID();
		}
		$story = new Story( $post_id );
		if ( ! $story->is_valid() ) {
			return 0;
		}
		$rendered_post_content = \apply_filters( 'the_content', \get_post_field( 'post_content', $post_id ) );
		return substr_count( $rendered_post_content, '<img ' );
	}

	/**
	 * Get the last modified time of the post.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return int The post's last modified time in UNIX format.
	 */
	public static function get_modified_time( $post_id = null ) {
		if ( ! $post_id ) {
			$post_id = \get_the_ID();
		}
		$story = new Story( $post_id );
		if ( ! $story->is_valid() ) {
			return '';
		}
		return \get_post_modified_time( 'U', true, $post_id );
	}

	/**
	 * Get the online publish time of the post.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return int The post's online publish time, in UNIX format.
	 */
	public static function get_publish_time( $post_id = null ) {
		if ( ! $post_id ) {
			$post_id = \get_the_ID();
		}
		$story = new Story( $post_id );
		if ( ! $story->is_valid() ) {
			return '';
		}

		// Only if published.
		if ( 'publish' !== \get_post_status( $post_id ) ) {
			return '';
		}
		return \get_post_time( 'U', true, $post_id );
	}

	/**
	 * Get the username of the user who published the post.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return string The username of the user who published the post.
	 */
	public static function get_published_user( $post_id ) {
		if ( ! $post_id ) {
			$post_id = \get_the_ID();
		}
		$story = new Story( $post_id );
		if ( ! $story->is_valid() ) {
			return '';
		}
		$field                 = self::get_field( 'published_by' );
		$current_field_value   = $field->get_value( $post_id );
		$previous_post_status = filter_input( INPUT_POST, 'original_post_status', FILTER_SANITIZE_SPECIAL_CHARS );
		$current_post_status  = \get_post_status( $post_id );
		$published_statuses   = [ 'publish', 'future' ];

		// Don't change the value if not updating the post status.
		if ( ! $previous_post_status ) {
			return $current_field_value;
		}

		// Remove the value if the post is no longer published.
		if ( ! in_array( $current_post_status, $published_statuses, true ) ) {
			return '';
		}

		if ( 'publish' !== $previous_post_status && in_array( $current_post_status, $published_statuses, true ) ) {
			$user = \wp_get_current_user();
			if ( $user ) {
				return $user->user_login;
			}
		}

		return '';
	}

	/**
	 * Get the authors assigned to the post.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return string[] Array of author names.
	 */
	public static function get_authors( $post_id = null ) {
		if ( ! $post_id ) {
			$post_id = \get_the_ID();
		}
		$story = new Story( $post_id );
		if ( ! $story->is_valid() ) {
			return [];
		}
		$authors = [ get_the_author_meta( 'display_name', \get_post_field( 'post_author', $post_id ) ) ];
		if ( function_exists( 'get_coauthors' ) ) {
			$authors = array_map(
				function( $author ) {
					return $author->display_name;
				},
				\get_coauthors( $post_id )
			);
		}
		if ( empty( $authors ) ) {
			return [];
		}
		return $authors;
	}

	/**
	 * Check if the post is currently locked.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return bool True if the post is locked, false otherwise.
	 */
	public static function is_post_locked( $post_id = null ) {
		if ( ! $post_id ) {
			$post_id = \get_the_ID();
		}
		$story = new Story( $post_id );
		if ( ! $story->is_valid() ) {
			return false;
		}
		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		return (bool) \wp_check_post_lock( $post_id );
	}

	/**
	 * Get the status of the post in WordPress.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return string The status of the post in WordPress.
	 */
	public static function get_post_wp_status( $post_id = null ) {
		if ( ! $post_id ) {
			$post_id = \get_the_ID();
		}
		$story = new Story( $post_id );
		if ( ! $story->is_valid() ) {
			return false;
		}
		return $story->post->post_status;
	}
}
