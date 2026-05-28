<?php
/**
 * Newspack Story Budget Story
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget;

use Newspack_Story_Budget\Fields;

/**
 * Story Class.
 */
class Story {
	/**
	 * Story ID.
	 *
	 * @var int
	 */
	public $id;

	/**
	 * Story post object.
	 *
	 * @var \WP_Post
	 */
	public $post;

	/**
	 * Constructor.
	 *
	 * @param int|\WP_Post $post Story ID or post object.
	 */
	public function __construct( $post ) {
		if ( $post instanceof \WP_Post ) {
			$this->id   = $post->ID;
			$this->post = $post;
		} else {
			$this->id   = $post;
			$this->post = get_post( $post );
		}
	}

	/**
	 * Whether it's a valid story.
	 *
	 * @return bool
	 */
	public function is_valid() {
		return ! empty( $this->id ) &&
			! empty( $this->post ) &&
			! is_wp_error( $this->post ) &&
			in_array( $this->post->post_status, [ 'publish', 'draft', 'pending', 'future' ], true ) &&
			in_array( \get_post_type( $this->id ), Budgets::get_post_types(), true );
	}

	/**
	 * Get budget IDs assigned to this story.
	 *
	 * @return int[]
	 */
	public function get_budgets() {
		return \wp_get_post_terms( $this->id, Budgets::TAXONOMY, [ 'fields' => 'ids' ] );
	}

	/**
	 * Update budgets assigned to this story.
	 *
	 * @param int[]|string[] $budget_ids Budget IDs or slugs to assign to this story.
	 * @param bool           $append     Whether to append the new budget IDs to the existing ones or replace all existing IDs.
	 *
	 * @return bool True if updated successfully, otherwise false.
	 */
	public function update_budgets( $budget_ids = [], $append = false ) {
		$budget_ids = array_map(
			function( $budget_id ) {
				$budget_term = \get_term_by(
					is_numeric( $budget_id ) ? 'term_id' : 'slug',
					$budget_id,
					Budgets::TAXONOMY
				);
				if ( ! $budget_term ) {
					return new \WP_Error(
						'invalid_budget_id',
						sprintf(
							// Translators: %s is the budget ID or slug.
							__( 'Invalid budget ID or slug "%s".', 'newspack-story-budget' ),
							$budget_id
						)
					);
				}
				return $budget_term->term_id;
			},
			(array) $budget_ids
		);

		return \wp_set_object_terms(
			$this->id,
			$budget_ids,
			\Newspack_Story_Budget\Budgets::TAXONOMY,
			$append
		);
	}

	/**
	 * Remove budget IDs from this story.
	 *
	 * @param int[] $budget_ids Budget IDs to remove from this story.
	 *
	 * @return bool True if removed successfully, otherwise false.
	 */
	public function remove_budgets( $budget_ids = [] ) {
		if ( empty( $budget_ids ) ) {
			return false;
		}
		return \wp_remove_object_terms( $this->id, $budget_ids, \Newspack_Story_Budget\Budgets::TAXONOMY );
	}

	/**
	 * Update one or more story fields.
	 *
	 * @param array $fields Array of fields to update, keyed by field slug.
	 *
	 * @return bool|\WP_Error True if updated successfully, otherwise WP_Error.
	 */
	public function update( $fields = [] ) {
		$updated = false;
		foreach ( $fields as $slug => $value ) {
			$field = Fields::get_field( $slug );

			// Only editable fields.
			if ( ! $field || ! $field->is_editable() ) {
				return new \WP_Error(
					'invalid_field',
					sprintf(
						// Translators: field slug.
						__( 'Invalid field "%s".', 'newspack-story-budget' ),
						$slug
					)
				);
			}
			// Skip unaltered fields.
			if ( $field->get_value( $this->id ) === $value ) {
				continue;
			}
			$result = $field->update_value( $this->id, $value );
			if ( false === $result ) {
				return new \WP_Error(
					'failed_to_update_field',
					sprintf(
						// Translators: field slug.
						__( 'Failed to update field "%s".', 'newspack-story-budget' ),
						$slug
					)
				);
			}
			$updated = true;
		}
		if ( ! $updated ) {
			return new \WP_Error(
				'missing_field',
				__( 'No fields updated.', 'newspack-story-budget' ),
				[ 'status' => 400 ]
			);
		}
		return $updated;
	}

	/**
	 * Refresh read-only fields for the story.
	 */
	public function refresh() {
		Fields::update_read_only_fields( $this->id );
	}

	/**
	 * Get story in array format.
	 *
	 * @param bool $include_metadata Whether to include metadata.
	 *
	 * @return array
	 */
	public function to_array( $include_metadata = true ) {
		$all_fields = Fields::get_all_fields();
		$values     = [
			'id' => $this->id,
		];

		if ( $include_metadata ) {
			$values['metadata'] = $this->get_metadata();
		}

		foreach ( $all_fields as $field ) {
			$value = $field->get_value( $this->id );
			if ( is_null( $value ) ) {
				continue;
			}
			if ( in_array( $field->get_type(), [ 'text', 'longtext', 'date', 'datetime' ], true ) && empty( $value ) ) {
				continue;
			}
			$values[ $field->get_slug() ] = $value;
		}
		return $values;
	}

	/**
	 * Get story metadata.
	 *
	 * @return array
	 */
	public function get_metadata() {
		$metadata = [
			'slug'         => \get_post_field( 'post_name', $this->post ),
			'preview_url'  => \add_query_arg( 'newspack-story-preview', true, get_permalink( $this->id ) ),
			'edit_url'     => \get_edit_post_link( $this->id, 'edit' ),
			'can_edit'     => current_user_can( 'edit_post', $this->id ),
			'can_preview'  => $this->can_preview(),
			/**
			 * Filters the fields properties for the story.
			 *
			 * Field properties are metadata specific to a field in a story.
			 *
			 * @param array $fields_props The fields properties, each key is a field slug and the value is the field properties.
			 * @param int   $story_id     The story ID.
			 */
			'fields_props' => apply_filters( 'newspack_story_budget_fields_props', [], $this->id ),
		];

		/**
		 * Filters the story metadata.
		 *
		 * @param array $metadata The story metadata.
		 * @param int   $story_id The story ID.
		 */
		return apply_filters( 'newspack_story_budget_story_metadata', $metadata, $this->id );
	}

	/**
	 * Whether the story can be previewed by the current user.
	 *
	 * @return bool
	 */
	public function can_preview() {
		return current_user_can( 'edit_post', $this->id ) || 'publish' === $this->post->post_status;
	}
}
