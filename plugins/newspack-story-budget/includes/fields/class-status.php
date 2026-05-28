<?php
/**
 * Newspack Story Budget - Status class.
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget\Fields;

use WP_Error;
use WP_Term;

/**
 * Class representing a single status.
 */
class Status {
	/**
	 * The status slug.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * The status label.
	 *
	 * @var string
	 */
	private $label;

	/**
	 * The required capability to use this status.
	 *
	 * @var string|null
	 */
	private $required_capability;

	/**
	 * The order of the status.
	 *
	 * @var int
	 */
	private $order = 0;

	/**
	 * Any errors that occurred during creation.
	 *
	 * @var WP_Error|null
	 */
	private $errors;

	/**
	 * Cache of user can use results.
	 *
	 * @var array
	 */
	private $user_can_cache = [];

	/**
	 * Constructor.
	 *
	 * @param WP_Term|string $term_or_slug WP_Term object or status slug.
	 */
	public function __construct( $term_or_slug ) {
		$this->errors = new WP_Error();

		if ( $term_or_slug instanceof WP_Term ) {
			$this->init_from_term( $term_or_slug );
		} else {
			$this->initialize_by_slug( $term_or_slug );
		}
	}

	/**
	 * Initialize from a WP_Term object.
	 *
	 * @param WP_Term $term The term object.
	 */
	private function init_from_term( $term ) {
		$this->slug = $term->slug;
		$this->label = $term->name;
		$this->required_capability = get_term_meta( $term->term_id, Statuses::CAPABILITY_META_KEY, true );
		$this->order = (int) get_term_meta( $term->term_id, Statuses::ORDER_META_KEY, true );
	}

	/**
	 * Initialize by slug.
	 *
	 * @param string $slug The status slug.
	 */
	private function initialize_by_slug( $slug ) {
		if ( empty( $slug ) ) {
			$this->errors->add(
				'missing_slug',
				__( 'Status slug is required.', 'newspack-story-budget' )
			);
			return;
		}

		$this->slug = $slug;

		$term = get_term_by( 'slug', $slug, Statuses::TAXONOMY );
		if ( $term ) {
			$this->label = $term->name;
			$this->required_capability = get_term_meta( $term->term_id, Statuses::CAPABILITY_META_KEY, true );
			$this->order = get_term_meta( $term->term_id, Statuses::ORDER_META_KEY, true ) || 0;
		} else {
			// If term doesn't exist, set an error.
			$this->errors->add(
				'invalid_slug',
				__( 'Invalid status slug.', 'newspack-story-budget' )
			);
		}
	}

	/**
	 * Get the status slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return $this->slug;
	}

	/**
	 * Get the status label.
	 *
	 * @return string
	 */
	public function get_label() {
		return $this->label;
	}

	/**
	 * Get the required capability.
	 *
	 * @return string|null
	 */
	public function get_required_capability() {
		return $this->required_capability;
	}

	/**
	 * Get the order of the status.
	 *
	 * @return int
	 */
	public function get_order() {
		return $this->order;
	}
	/**
	 * Whether the current user can use this status.
	 *
	 * @return bool Whether the current user can use this status.
	 */
	public function current_user_can() {
		return $this->user_can( get_current_user_id() );
	}

	/**
	 * Whether a user can use this status.
	 *
	 * @param int $user_id User ID.
	 * @return bool Whether the user can use this status.
	 */
	public function user_can( $user_id ) {
		if ( empty( $this->required_capability ) ) {
			return true;
		}

		// Memoize the result.
		if ( isset( $this->user_can_cache[ $user_id ] ) ) {
			return $this->user_can_cache[ $user_id ];
		}

		$this->user_can_cache[ $user_id ] = user_can( $user_id, $this->required_capability );

		return $this->user_can_cache[ $user_id ];
	}

	/**
	 * Whether there were any errors during creation.
	 *
	 * @return bool
	 */
	public function has_errors() {
		return $this->errors->has_errors();
	}

	/**
	 * Get any errors that occurred during creation.
	 *
	 * @return WP_Error|null
	 */
	public function get_errors() {
		return $this->errors;
	}

	/**
	 * Convert the status to an array.
	 *
	 * @return array
	 */
	public function to_array() {
		return [
			'value'               => $this->get_slug(),
			'label'               => $this->get_label(),
			'required_capability' => $this->get_required_capability(),
			'user_can_apply'      => $this->current_user_can(),
		];
	}
}
