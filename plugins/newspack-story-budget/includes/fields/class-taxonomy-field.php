<?php
/**
 * Newspack Story Budget - Taxonomy field.
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget\Fields;

use Newspack_Story_Budget\Budgets;
use Newspack_Story_Budget\Fields as Fields_Class;

/**
 * Class for taxonomy fields.
 */
class Taxonomy_Field extends Abstract_Field {
	/**
	 * Whether the field is multiple.
	 *
	 * @var bool
	 */
	protected $is_multiple = true;

	/**
	 * The taxonomy to use for the field.
	 *
	 * @var string
	 */
	protected $taxonomy = '';

	/**
	 * The taxonomy object.
	 *
	 * @var \WP_Taxonomy
	 */
	protected $taxonomy_object = null;

	/**
	 * Optional callback used to determine if the current user can edit the value of this field, if editable. Defaults to the taxonomy's assign_terms capability.
	 *
	 * Note that the user must also be allowed to edit the post itself. This callback doesn't need to check that.
	 *
	 * @var callable|null
	 */
	protected $permission_callback = null;

	/**
	 * Register a taxonomy field.
	 *
	 * @param array $args {
	 *    Configuration for registering a taxonomy field. See abstract class constructor for additional params.
	 *    @type string   $taxonomy The taxonomy to use for the field.
	 *    @type callable $permission_callback? Optional callback used to determine if the current user can edit the field value. Defaults to the taxonomy's assign_terms capability.
	 * }
	 */
	public function __construct( $args ) {
		parent::__construct( $args );

		// These fields are never searchable or shown in the WP posts table and editor.
		$this->is_searchable = false;
		$this->show_in_wp_posts_table = false;
		$this->show_in_editor = false;

		if ( empty( $args['taxonomy'] ) ) {
			$this->errors->add( 'newspack_story_budget_taxonomy_field_taxonomy_required', __( 'Taxonomy is required.', 'newspack-story-budget' ) );
			return;
		}

		$this->taxonomy = $args['taxonomy'];
		$this->taxonomy_object = get_taxonomy( $this->taxonomy );
		if ( ! $this->taxonomy_object ) {
			$this->errors->add( 'newspack_story_budget_taxonomy_field_invalid_taxonomy', __( 'Invalid taxonomy.', 'newspack-story-budget' ) );
			return;
		}

		if ( ! $this->taxonomy_object->hierarchical ) {
			$this->errors->add( 'newspack_story_budget_taxonomy_field_non_hierarchical_taxonomies_not_supported', __( 'Non-hierarchical taxonomies are not supported.', 'newspack-story-budget' ) );
			return;
		}
	}

	/**
	 * Get taxonomy terms options.
	 *
	 * @return array
	 */
	public function get_options() {
		$terms = get_terms(
			[
				'taxonomy'   => $this->taxonomy,
				'hide_empty' => false,
			]
		);

		if ( $this->taxonomy_object->hierarchical ) {
			$terms = $this->sort_hierarchical_terms( $terms );
		}

		$options = array_map(
			function( $term ) {
				return [
					'label' => $this->get_option_label( $term ),
					'value' => $term->term_id,
				];
			},
			$terms
		);
		return $options;
	}

	/**
	 * Whether the field is editable.
	 *
	 * @return bool
	 */
	public function is_editable() {
		return ! empty(
			get_terms(
				[
					'taxonomy'   => $this->taxonomy,
					'hide_empty' => false,
				]
			)
		);
	}

	/**
	 * Sort hierarchical terms.
	 *
	 * @param \WP_Term[] $terms The array to sort.
	 *
	 * @return \WP_Term[] The sorted array.
	 */
	protected function sort_hierarchical_terms( $terms ) {
		$terms_by_id = [];
		$terms_by_parent = [];

		foreach ( $terms as $term ) {
			$terms_by_id[ $term->term_id ] = $term;
			$terms_by_parent[ $term->parent ][] = $term;
		}

		// Sort each group by id.
		foreach ( $terms_by_parent as &$group ) {
			usort( $group, fn( $a, $b ) => $a->term_id <=> $b->term_id );
		}

		/**
		 * Build the flat sorted array.
		 *
		 * @param int|null   $parent_id The parent ID.
		 * @param \WP_Term[] $terms_by_parent The terms by parent.
		 *
		 * @return \WP_Term[] The sorted array.
		 */
		$build_sorted_array = function( $parent_id, $terms_by_parent ) use ( &$build_sorted_array ) {
			$result = [];

			if ( ! isset( $terms_by_parent[ $parent_id ] ) ) {
				return $result;
			}

			foreach ( $terms_by_parent[ $parent_id ] as $term ) {
				$result[] = $term;
				// Recursively add children.
				$children = $build_sorted_array( $term->term_id, $terms_by_parent );
				$result = array_merge( $result, $children );
			}
			return $result;
		};
		return $build_sorted_array( 0, $terms_by_parent );
	}

	/**
	 * Get the label for a term option.
	 *
	 * If term has parent, the parent's name will be prepended to the term's name.
	 *
	 * @param object $term The term object.
	 *
	 * @return string
	 */
	protected function get_option_label( $term ) {
		$label = $term->name;
		if ( $term->parent ) {
			$parent = get_term( $term->parent );
			$label  = $this->get_option_label( $parent ) . ' — ' . $label;
		}
		return $label;
	}

	/**
	 * Get an array representation of the field.
	 *
	 * @return array
	 */
	public function to_array() {
		$parent_array = parent::to_array();
		return array_merge(
			$parent_array,
			[
				'options'  => $this->get_options(),
				'taxonomy' => $this->taxonomy,
			]
		);
	}

	/**
	 * Get the field value.
	 *
	 * @param int $post_id The post ID to get the value for.
	 *
	 * @return int[] The field value.
	 */
	public function get_value( $post_id ) {
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return [];
		}
		$terms = get_the_terms( $post_id, $this->taxonomy );
		if ( ! $terms ) {
			return [];
		}
		return array_map(
			function( $term ) {
				return $term->term_id;
			},
			$terms
		);
	}

	/**
	 * Update the value of the field.
	 *
	 * @param int   $post_id The post ID to update the value for.
	 * @param mixed $value   The new value of the field.
	 *
	 * @return bool True if updated successfully, otherwise false.
	 */
	public function update_value( $post_id, $value ) {
		if ( ! is_array( $value ) ) {
			$value = [ $value ];
		}
		$result = wp_set_object_terms( $post_id, array_map( 'intval', $value ), $this->taxonomy );
		if ( is_wp_error( $result ) ) {
			return false;
		}
		Fields_Class::update_modified( $post_id );
		return true;
	}

	/**
	 * Add a value for the field.
	 *
	 * @param int   $post_id The post ID to update the value for.
	 * @param mixed $value   The value to add.
	 *
	 * @return bool True if updated successfully, otherwise false.
	 */
	public function add_value( $post_id, $value ) {
		if ( ! is_array( $value ) ) {
			$value = [ $value ];
		}
		$result = wp_set_object_terms( $post_id, array_map( 'intval', $value ), $this->taxonomy, true );
		if ( is_wp_error( $result ) ) {
			return false;
		}
		Fields_Class::update_modified( $post_id );
		return true;
	}

	/**
	 * Delete the value of the field.
	 *
	 * @param int   $post_id The post ID to reset the value for.
	 * @param mixed $value   If provided, only delete the passed value. Defaults to all values.
	 *
	 * @return bool True if reset successfully, otherwise false.
	 */
	public function delete_value( $post_id, $value = null ) {
		if ( empty( $value ) ) {
			$value = $this->get_value( $post_id );
		}
		if ( ! is_array( $value ) ) {
			$value = [ $value ];
		}
		$result = wp_remove_object_terms( $post_id, array_map( 'intval', $value ), $this->taxonomy );
		if ( is_wp_error( $result ) ) {
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
		if ( $this->permission_callback && is_callable( $this->permission_callback ) ) {
			return call_user_func( $this->permission_callback, $user_id );
		}
		$taxonomy = get_taxonomy( $this->taxonomy );
		if ( ! $taxonomy ) {
			return false;
		}
		return user_can( $user_id, $taxonomy->cap->assign_terms );
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
