<?php
/**
 * Newspack Story Budget - abstract class for a story budget field.
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget\Fields;

use Newspack_Story_Budget\Budgets;

/**
 * Class for editable fields.
 */
class Read_Only_Field extends Abstract_Field {
	/**
	 * Register the field.
	 *
	 * @param array $args {
	 *    Configuration for registering a read-only field. See abstract class constructor for additional params.
	 *    @type callable $get_value_callback? Optional callback used to dynamically calculate the value of a read-only field.
	 *    @type callable $post_save_callback? Optional callback used to calculate the value of a read-only field on post_save.
	 * }
	 */
	public function __construct( $args ) {

		parent::__construct( $args );
		if ( is_null( $this->get_value_callback ) && is_null( $this->post_save_callback ) ) {
			$this->errors->add(
				'newspack_story_budget_invalid_field_configuration',
				__( 'Read-only fields must receive a callback function to calculate their value.', 'newspack-story-budget' )
			);
		}
	}

	/**
	 * Get the field's save_value_hook name.
	 *
	 * @return string The field's save_value_hook.
	 */
	public function get_save_value_hook() {
		return $this->save_value_hook;
	}

	/**
	 * Get the field's value.
	 *
	 * @param int $post_id The post ID to get the value for. If not passed, return the default value, if any.
	 *
	 * @return mixed The field's value or WP_Error.
	 */
	public function get_value( $post_id ) {
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return null;
		}

		// Dynamically calculate the value.
		if ( $this->get_value_callback && is_callable( $this->get_value_callback ) ) {
			return call_user_func( $this->get_value_callback, $post_id );
		}

		// Get the stored value.
		if ( $this->post_save_callback && is_callable( $this->post_save_callback ) ) {
			$post_meta = \get_post_meta( $post_id, $this->get_post_meta_name(), true );
			// Post meta will return an empty string if the value is not set.
			if ( '' === $post_meta ) {
				return null;
			}
			return $this->cast_value( $post_meta );
		}

		return null;
	}

	/**
	 * Return an error message if attempting to update a read-only field value.
	 *
	 * @param int   $post_id The post ID to update the value for.
	 * @param mixed $value The new value of the field.
	 *
	 * @return WP_Error
	 */
	public function update_value( $post_id = null, $value = null ) {
		return new \WP_Error(
			'newspack_story_budget_read_only_field',
			__( "Cannot update a read-only field's value.", 'newspack-story-budget' )
		);
	}

	/**
	 * Return an error message if attempting to add a read-only field value.
	 *
	 * @return WP_Error
	 */
	public function add_value() {
		return $this->update_value();
	}

	/**
	 * Return an error message if attempting to delete a read-only field value.
	 *
	 * @return WP_Error
	 */
	public function delete_value() {
		return $this->update_value();
	}
}
