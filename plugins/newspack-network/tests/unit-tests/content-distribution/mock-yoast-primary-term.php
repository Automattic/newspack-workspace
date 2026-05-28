<?php // phpcs:ignore
/**
 * WPSEO plugin file.
 *
 * @package WPSEO
 */

/**
 * Represents a post's primary term.
 */
class WPSEO_Primary_Term {

	const META_NAME = '_yoast_wpseo_primary_category';

	/**
	 * Taxonomy name for the term.
	 *
	 * @var string
	 */
	protected $taxonomy_name;

	/**
	 * Post ID for the term.
	 *
	 * @var int
	 */
	protected $post_ID;

	/**
	 * The taxonomy this term is part of.
	 *
	 * @param string $taxonomy_name Taxonomy name for the term.
	 * @param int    $post_id       Post ID for the term.
	 */
	public function __construct( $taxonomy_name, $post_id ) {
		$this->taxonomy_name = $taxonomy_name;
		$this->post_ID       = $post_id;
	}

	/**
	 * Returns the primary term ID.
	 *
	 * @return int|bool
	 */
	public function get_primary_term() {
		return get_post_meta( $this->post_ID, self::META_NAME, true );
	}

	/**
	 * Sets the new primary term ID.
	 *
	 * @param int $new_primary_term New primary term ID.
	 *
	 * @return void
	 */
	public function set_primary_term( $new_primary_term ) {
		update_post_meta( $this->post_ID, self::META_NAME, $new_primary_term );
	}
}
