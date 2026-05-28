<?php
/**
 * Newspack Story Budget - abstract class for a story budget field.
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget\Fields;

use Newspack_Story_Budget\Budgets;
use Newspack_Story_Budget\Fields as Fields_Class;

/**
 * Class for editable fields.
 */
class Editable_Field extends Abstract_Field {

	/**
	 * Optional callback to calculate a default value for the field.
	 *
	 * @var mixed
	 */
	protected $default_value = null;
	/**
	 * Whether the field is editable or read-only.
	 *
	 * @var bool
	 */
	protected $is_editable = true;

	/**
	 * Optional possible values for the field. If given, the field's value must be one of these.
	 *
	 * @var mixed
	 */
	protected $options = [];

	/**
	 * Optional callback used to determine if the current user can edit the value of this field, if editable. Defaults to true.
	 *
	 * Note that the user must also be allowed to edit the post itself. This callback doesn't need to check that.
	 *
	 * @var callable|null
	 */
	protected $permission_callback = null;

	/**
	 * Register an editable field.
	 *
	 * @param array $args {
	 *    Configuration for registering an editable field. See abstract class constructor for additional params.
	 *    @type callable $permission_callback? Optional callback used to determine if the current user can edit the field value.
	 *    @type mixed    $default_value?       Optional callback to calculate default value for the field.
	 *    @type callable $save_value_callback? Optional callback used to save the value of the field to a post. Use if the field value needs to be fetched and stored someplace other than post meta.
	 *    @type array    $options? {
	 *        Optional possible values for the field. These will be rendered in UI as a select dropdown, or a multiselect if $is_multiple is also true.
	 *        @type string     $label                The label for the option.
	 *        @type string|int $value                The value for the option.
	 *        @type bool       $permission_callback? Optional callback to determine if the current user can select this option. If not provided, the option will always be selectable.
	 *        @type bool       $selected?            If true, this option is selected by default.
	 *    }
	 * }
	 */
	public function __construct( $args ) {
		parent::__construct( $args );

		$this->is_multiple         = ! empty( $args['is_multiple'] );
		$this->default_value       = ! empty( $args['default_value'] ) && is_callable( $args['default_value'] ) ? $args['default_value'] : $this->default_value;
		$this->permission_callback = ! empty( $args['permission_callback'] ) && is_callable( $args['permission_callback'] ) ? $args['permission_callback'] : $this->permission_callback;

		if ( ! empty( $args['options'] ) ) {
			$this->options = $args['options'];
			if ( empty( $this->default_value ) || ! is_callable( $this->default_value ) ) {
				$default_values = [];
				foreach ( $this->options as $option ) {
					if ( ! empty( $option['selected'] ) ) {
						$default_values[] = $option['value'];
						if ( ! $this->is_multiple ) {
							break;
						}
					}
				}
				if ( ! empty( $default_values ) ) {
					$default_value = $this->is_multiple ? $default_values : reset( $default_values );
					$this->default_value = function() use ( $default_value ) {
						return $default_value;
					};
				}
			}
		}
	}

	/**
	 * Get an array representation of the field.
	 */
	public function to_array() {
		$parent_array = parent::to_array();
		return array_merge(
			$parent_array,
			[
				'options' => $this->options,
			]
		);
	}

	/**
	 * Get the field's value.
	 *
	 * @param int   $post_id The post ID to get the value for. If not passed, return the default value, if any.
	 * @param mixed $default_value The default value to return if no post ID is passed or no value has been set.
	 *                             Allows the default value to be dynamic at the point of retrieval, if needed.
	 *
	 * @return mixed The field's value or WP_Error.
	 */
	public function get_value( $post_id, $default_value = null ) {
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return null;
		}

		$default_value = is_callable( $this->default_value ) ? call_user_func( $this->default_value, $post_id ) : $default_value;
		if ( $this->is_multiple && ! is_array( $default_value ) ) {
			$default_value = is_null( $default_value ) ? [] : [ $default_value ];
		}
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return $default_value;
		}

		if ( ! is_null( $this->get_value_callback ) ) {
			return call_user_func( $this->get_value_callback, $post_id );
		}

		$value = \get_post_meta( $post_id, $this->get_post_meta_name(), ! $this->is_multiple );
		return ! empty( $value ) ? $this->cast_value( $value ) : $default_value;
	}


	/**
	 * Update the value of the field.
	 * Only editable fields can have their value updated directly.
	 *
	 * @param int   $post_id The post ID to update the value for.
	 * @param mixed $value The new value of the field.
	 *
	 * @return bool True if updated successfully, otherwise false.
	 */
	public function update_value( $post_id, $value ) {
		if ( ! is_null( $this->save_value_callback ) ) {
			Fields_Class::update_modified( $post_id );
			return call_user_func( $this->save_value_callback, $post_id, $value );
		}

		// If passed an empty value, clear all values for this field.
		if ( empty( $value ) ) {
			return $this->delete_value( $post_id );
		}

		// For is_multiple fields, add and remove values.
		if ( $this->is_multiple ) {
			if ( ! is_array( $value ) ) {
				$value = [ $value ];
			}
			$existing_values = $this->get_value( $post_id );
			$values_to_add   = array_values(
				array_diff(
					$value,
					$existing_values
				)
			);
			$values_to_remove = array_values(
				array_diff(
					$existing_values,
					$value
				)
			);
			foreach ( $values_to_add as $value ) {
				$this->add_value( $post_id, $value );
			}
			foreach ( $values_to_remove as $value ) {
				$this->delete_value( $post_id, $value );
			}
			return true;
		}
		return $this->update_stored_value( $post_id, $value );
	}

	/**
	 * Add a value for the field.
	 * Only editable fields can have their value updated directly.
	 *
	 * @param int   $post_id The post ID to update the value for.
	 * @param mixed $value The value to add.
	 *
	 * @return bool True if updated successfully, otherwise false.
	 */
	public function add_value( $post_id, $value ) {
		if ( ! $this->is_multiple ) {
			_doing_it_wrong( __FUNCTION__, 'Only fields with the $is_multiple property can add multiple values.', '0.0.0' );
			return $this->update_value( $post_id, $value );
		}

		if ( ! is_null( $this->save_value_callback ) ) {
			$current_values = $this->get_value( $post_id, [] );
			$new_values     = array_values( array_unique( array_merge( $current_values, [ $value ] ) ) );
			Fields_Class::update_modified( $post_id );
			return call_user_func( $this->save_value_callback, $post_id, $new_values );
		}

		return $this->add_stored_value( $post_id, $value );
	}

	/**
	 * Delete the value of the field.
	 *
	 * @param int   $post_id The post ID to reset the value for.
	 * @param mixed $value If provided, only delete the value if it matches this value. Useful for fields with multiple values.
	 *
	 * @return bool True if reset successfully, otherwise false.
	 */
	public function delete_value( $post_id, $value = '' ) {
		if ( ! in_array( \get_post_type( $post_id ), Budgets::get_post_types(), true ) ) {
			return false;
		}

		if ( ! is_null( $this->save_value_callback ) ) {
			$current_values = $this->get_value( $post_id, [] );
			if ( in_array( $value, $current_values, true ) ) {
				$new_values = array_values( array_diff( $current_values, [ $value ] ) );
			} else {
				return false;
			}
			Fields_Class::update_modified( $post_id );
			return call_user_func( $this->save_value_callback, $post_id, $new_values );
		}

		$updated = \delete_post_meta( $post_id, $this->get_post_meta_name(), $value );
		if ( ! $updated ) {
			return false;
		}
		Fields_Class::update_modified( $post_id );
		return true;
	}

	/**
	 * Whether a user can edit this field.
	 *
	 * @param int $user_id The user ID.
	 * @return bool
	 */
	public function user_can_edit( $user_id ) {
		if ( ! $this->is_editable() ) {
			return false;
		}
		if ( ! $this->permission_callback || ! is_callable( $this->permission_callback ) ) {
			return true;
		}
		return call_user_func( $this->permission_callback, $user_id );
	}

	/**
	 * Whether the current user can edit the field.
	 *
	 * @return bool
	 */
	public function current_user_can_edit() {
		return $this->user_can_edit( get_current_user_id() );
	}
}
