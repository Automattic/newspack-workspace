<?php
/**
 * Newspack Story Budget - abstract class for a story budget field.
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget\Fields;

use Newspack_Story_Budget\Budgets;
use Newspack_Story_Budget\Fields;

/**
 * Abstract class to represent a single story budget field.
 */
abstract class Abstract_Field {
	/**
	 * The prefix for all field names when getting or setting as post meta.
	 */
	const FIELD_PREFIX = '_np_story_budget_';

	/**
	 * Optional description for the field, if editable.
	 *
	 * @var string
	 */
	protected $description = '';

	/**
	 * Whether the field is editable or read-only.
	 *
	 * @var bool
	 */
	protected $is_editable = false;

	/**
	 * Whether the field can be used to filter stories.
	 * yes - the field can be used to filter stories.
	 * no - the field cannot be used to filter stories.
	 * always - the field can be used to filter stories, and is always visible in the filter bar.
	 *
	 * @var string yes|no|always
	 */
	protected $is_filterable = 'no';

	/**
	 * If true, the field's value is an array of values.
	 *
	 * @var bool
	 */
	protected $is_multiple = false;

	/**
	 * Whether the field can be used to search stories.
	 *
	 * @var bool
	 */
	protected $is_searchable = false;

	/**
	 * Whether the field can be used to sort stories.
	 *
	 * @var bool
	 */
	protected $is_sortable = false;

	/**
	 * The human-readable name of the field.
	 *
	 * @var string
	 */
	protected $name;

	/**
	 * Whether the field should be shown in the dynamic Budgets table.
	 *
	 * @var bool
	 */
	protected $show_in_table = false;

	/**
	 * Whether the field should be always visible in the dynamic Budgets table.
	 *
	 * @var bool
	 */
	protected $always_visible_in_table = false;

	/**
	 * Whether the field should be shown in the WP post editor sidebar.
	 *
	 * @var bool
	 */
	protected $show_in_editor = false;

	/**
	 * Whether the field should be shown in the WP posts table.
	 *
	 * @var bool
	 */
	protected $show_in_wp_posts_table = false;

	/**
	 * The unique slug for the field.
	 *
	 * @var string
	 */
	protected $slug;

	/**
	 * The type of the field's data. One of: boolean, number, text, date, datetime
	 *
	 * @var string
	 */
	protected $type = 'text';

	/**
	 * The default order of the field in the WP posts table.
	 *
	 * @var int
	 */
	protected $default_order = PHP_INT_MAX;

	/**
	 * Optional callback used to dynamically calculate the value of the field.
	 *
	 * @var callable|null
	 */
	protected $get_value_callback = null;

	/**
	 * Optional callback used to save the value of the field to a post.
	 * Use if the field value needs to be fetched and stored someplace other than post meta.
	 * Defaults to saving the value as post meta.
	 *
	 * @var callable|null
	 */
	protected $save_value_callback = null;

	/**
	 * Optional callback used to calculate the value of the field on post_save.
	 * If provided, the value will be calculated stored as post meta on post update.
	 *
	 * @var callable|null
	 */
	protected $post_save_callback = null;

	/**
	 * Errors that occurred during field initialization.
	 *
	 * @var \WP_Error
	 */
	protected $errors = null;

	/**
	 * Weather the field should be show when creating a new story via add new story modal.
	 *
	 * @var bool
	 */
	protected $show_in_add_new_story = false;

	/**
	 * Object contructor.
	 *
	 * @param array $args {
	 *    Configuration for initializing a field.
	 *    @type string   $description?            Optional description of the field's purpose.
	 *    @type bool     $is_editable?            Whether the field is editable or read-only.
	 *    @type string   $is_filterable?          Whether the field can be used to filter stories.
	 *    @type bool     $is_multiple?            If true, the field's value is an array of values.
	 *    @type bool     $is_searchable?          Whether the field can be used to search stories.
	 *    @type bool     $is_sortable?            Whether the field can be used to sort stories.
	 *    @type string   $name                    The human-readable name of the field.
	 *    @type string   $show_in_table?          Whether the field should be shown in the dynamic Budgets table.
	 *    @type bool     $show_in_add_new_story? Whether the field should be shown in the add new story modal.
	 *    @type string   $show_in_editor?         Whether the field should be shown in the WP post editor sidebar.
	 *    @type string   $show_in_wp_posts_table? Whether the field should be shown in the WP posts table.
	 *    @type string   $slug?                   The unique slug ID for the field. If not given, will be generated from the name.
	 *    @type string   $type                    The type of the field's data.
	 * }
	 */
	public function __construct( $args ) {
		$this->errors = new \WP_Error();

		$this->name                    = \sanitize_text_field( $args['name'] );
		$this->description             = isset( $args['description'] ) ? \sanitize_text_field( $args['description'] ) : $this->description;
		$this->slug                    = isset( $args['slug'] ) ? \sanitize_title( $args['slug'] ) : \sanitize_title( $this->name );
		$this->is_filterable           = isset( $args['is_filterable'] ) && in_array( $args['is_filterable'], [ 'yes', 'no', 'always' ], true ) ? \sanitize_text_field( $args['is_filterable'] ) : $this->is_filterable;
		$this->default_order           = isset( $args['default_order'] ) ? (float) $args['default_order'] : $this->default_order;
		$this->is_multiple             = isset( $args['is_multiple'] ) ? (bool) $args['is_multiple'] : $this->is_multiple;
		$this->is_searchable           = isset( $args['is_searchable'] ) ? (bool) $args['is_searchable'] : $this->is_searchable;
		$this->is_sortable             = isset( $args['is_sortable'] ) ? (bool) $args['is_sortable'] : $this->is_sortable;
		$this->show_in_table           = isset( $args['show_in_table'] ) ? (bool) $args['show_in_table'] : $this->show_in_table;
		$this->always_visible_in_table = isset( $args['always_visible_in_table'] ) ? (bool) $args['always_visible_in_table'] : $this->always_visible_in_table;
		$this->show_in_editor          = isset( $args['show_in_editor'] ) ? (bool) $args['show_in_editor'] : $this->show_in_editor;
		$this->show_in_wp_posts_table  = isset( $args['show_in_wp_posts_table'] ) ? (bool) $args['show_in_wp_posts_table'] : $this->show_in_wp_posts_table;
		$this->show_in_add_new_story   = isset( $args['show_in_add_new_story'] ) ? (bool) $args['show_in_add_new_story'] : $this->show_in_add_new_story;

		if ( ! empty( $args['type'] ) ) {
			$type = $this->set_type( $args['type'] );
			if ( \is_wp_error( $type ) ) {
				$this->errors->add( $type->get_error_code(), $type->get_error_message() );
			}
		}

		if ( 191 < strlen( $this->get_post_meta_name() ) ) {
			$this->errors->add(
				'newspack_story_budget_field_slug_too_long',
				sprintf(
					// Translators: the field slug.
					__( 'The field slug "%s" is too long. Please use a shorter slug.', 'newspack-story-budget' ),
					$this->slug
				)
			);
		}

		if ( ! empty( $args['get_value_callback'] ) && is_callable( $args['get_value_callback'] ) ) {
			$this->get_value_callback = $args['get_value_callback'];
		}

		if ( ! empty( $args['save_value_callback'] ) && is_callable( $args['save_value_callback'] ) ) {
			if ( ! $this->is_editable ) {
				$this->errors->add(
					'newspack_story_budget_field_save_value_callback_not_editable',
					__( 'The field is not editable, so the save_value_callback cannot be set.', 'newspack-story-budget' )
				);
			} else {
				$this->save_value_callback = $args['save_value_callback'];
			}
		}

		if ( ! empty( $args['post_save_callback'] ) && is_callable( $args['post_save_callback'] ) ) {
			if ( $this->is_editable ) {
				$this->errors->add(
					'newspack_story_budget_field_post_save_callback_not_editable',
					__( 'post_save_callback is only allowed for read only fields.', 'newspack-story-budget' )
				);
			} else {
				$this->post_save_callback = $args['post_save_callback'];
			}
		}
	}

	/**
	 * Get the field's post_save_callback.
	 *
	 * @return callable? The field's callback.
	 */
	public function get_post_save_callback() {
		return $this->post_save_callback;
	}

	/**
	 * Whether the field encountered any errors while being initialized.
	 *
	 * @return bool
	 */
	public function has_errors() {
		return $this->errors->has_errors();
	}

	/**
	 * Get any errors that occurred while initializing the field.
	 *
	 * @return WP_Error Field registration errors.
	 */
	public function get_errors() {
		return $this->errors;
	}

	/**
	 * Get the field's type.
	 *
	 * @return string The field's type.
	 */
	public function get_type() {
		return $this->type;
	}

	/**
	 * Get the field's default order.
	 *
	 * @return int The field's default order.
	 */
	public function get_default_order() {
		return $this->default_order;
	}

	/**
	 * Sets the field's data type.
	 *
	 * @param string $type The field's type.
	 */
	protected function set_type( $type ) {
		/**
		 * Filters the allowed data types.
		 *
		 * @param string[] $allowed_types The allowed data types.
		 */
		$allowed_types = apply_filters( 'newspack_story_budget_field_data_types', [ 'boolean', 'number', 'text', 'longtext', 'date', 'datetime' ] );
		if ( ! in_array( $type, $allowed_types, true ) ) {
			return new \WP_Error(
				'newspack_story_budget_invalid_field_configuration',
				sprintf(
					// Translators: 1: Field type passed in configuration, 2: Allowed field types.
					__( 'Invalid field type "%1$s". Must be one of: %2$s', 'newspack-story-budget' ),
					$type,
					implode( ', ', $allowed_types )
				)
			);
		}
		$this->type = $type;
		return $this->type;
	}

	/**
	 * Get the field's name.
	 *
	 * @return string The field's name.
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * Get the prefixed post meta field name.
	 *
	 * @return string The name of the post meta field.
	 */
	public function get_post_meta_name() {
		return self::FIELD_PREFIX . $this->get_slug();
	}

	/**
	 * Get the field's slug.
	 *
	 * @return string The field's slug.
	 */
	public function get_slug() {
		return $this->slug;
	}

	/**
	 * True if the field is editable, false if read-only.
	 *
	 * @return bool
	 */
	public function is_editable() {
		return $this->is_editable;
	}

	/**
	 * Get an array representation of the field.
	 */
	public function to_array() {
		return [
			'slug'                    => $this->get_slug(),
			'name'                    => $this->get_name(),
			'description'             => $this->description,
			'type'                    => $this->get_type(),
			'default_order'           => $this->default_order,
			'is_editable'             => $this->is_editable(),
			'is_multiple'             => $this->is_multiple,
			'is_filterable'           => $this->is_filterable,
			'is_searchable'           => $this->is_searchable(),
			'is_sortable'             => $this->is_sortable,
			'show_in_table'           => $this->show_in_table,
			'always_visible_in_table' => $this->always_visible_in_table,
			'show_in_editor'          => $this->show_in_editor,
			'show_in_wp_posts_table'  => $this->show_in_wp_posts_table,
			'show_in_add_new_story'   => $this->show_in_add_new_story,
		];
	}

	/**
	 * True if the field should be displayed in the wp posts table, false if not.
	 *
	 * @return bool
	 */
	public function show_in_wp_posts_table() {
		return $this->show_in_wp_posts_table;
	}

	/**
	 * True if the field should be shown in add new story modal, false if not.
	 *
	 * @return bool
	 */
	public function show_in_add_new_story() {
		return $this->show_in_add_new_story;
	}

	/**
	 * True if the field should be searchable, false if not.
	 *
	 * @return bool
	 */
	public function is_searchable() {
		return $this->is_searchable;
	}

	/**
	 * Get the field's value.
	 *
	 * @param int $post_id The post ID to get the value for. If not passed, return the default value, if any.
	 *
	 * @return mixed The field's value.
	 */
	abstract public function get_value( $post_id );

	/**
	 * Update the field's value.
	 *
	 * @param int   $post_id The post ID to update the value for.
	 * @param mixed $value The new value of the field.
	 *
	 * @return bool|WP_Error True if updated successfully, otherwise WP_Error.
	 */
	abstract public function update_value( $post_id, $value );

	/**
	 * Update the value of the field stored as post meta.
	 *
	 * @param int   $post_id The post ID to update the value for.
	 * @param mixed $value The new value of the field.
	 *
	 * @return bool True if updated successfully, otherwise false.
	 */
	protected function add_stored_value( $post_id, $value ) {
		if ( ! in_array( \get_post_type( $post_id ), Budgets::get_post_types(), true ) ) {
			return false;
		}

		$updated = \add_post_meta( $post_id, $this->get_post_meta_name(), $value );
		if ( ! $updated ) {
			return false;
		}
		return true;
	}

	/**
	 * Update the value of the field stored as post meta.
	 *
	 * @param int   $post_id The post ID to update the value for.
	 * @param mixed $value The new value of the field.
	 *
	 * @return bool True if updated successfully, otherwise false.
	 */
	protected function update_stored_value( $post_id, $value ) {
		if ( ! in_array( \get_post_type( $post_id ), Budgets::get_post_types(), true ) ) {
			return false;
		}

		$updated = \update_post_meta( $post_id, $this->get_post_meta_name(), $value );
		if ( ! $updated ) {
			return false;
		}
		Fields::update_modified( $post_id );
		return true;
	}

	/**
	 * Cast the value to the correct type.
	 *
	 * @param mixed $value The value to cast.
	 *
	 * @return mixed The cast value.
	 */
	protected function cast_value( $value ) {
		if ( $this->is_multiple && is_array( $value ) ) {
			return array_map( [ $this, 'cast_value' ], $value );
		}

		switch ( $this->type ) {
			case 'boolean':
				return (bool) $value;
			case 'date':
			case 'datetime':
				return (int) $value;
			case 'number':
				if ( is_numeric( $value ) ) {
					return ( floor( $value ) == $value ) ? (int) $value : (float) $value;
				}
				return 0;
			case 'text':
			case 'longtext':
				return (string) $value;
		}
		return $value;
	}
}
