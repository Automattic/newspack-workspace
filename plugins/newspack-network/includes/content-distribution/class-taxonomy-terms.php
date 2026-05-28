<?php
/**
 * Newspack Content Distribution Taxonomy Terms Handler
 *
 * @package Newspack
 */

namespace Newspack_Network\Content_Distribution;

use Newspack_Network\Content_Distribution as Content_Distribution_Class;

/**
 * Class to filter the canonical URLs for distributed content.
 */
class Taxonomy_Terms {

	const SEPARATOR = '|--|';

	/**
	 * Get taxonomies that should not be distributed.
	 *
	 * @return string[] The ignored taxonomies.
	 */
	public static function get_ignored_taxonomies() {
		$ignored_taxonomies = [
			'author', // Co-Authors Plus 'author' taxonomy should be ignored as it requires custom handling.
		];

		/**
		 * Filters the ignored taxonomies that should not be distributed.
		 *
		 * @param string[] $ignored_taxonomies The ignored taxonomies.
		 */
		return apply_filters( 'newspack_network_content_distribution_ignored_taxonomies', $ignored_taxonomies );
	}

	/**
	 * Get taxonomies that should always be distributed.
	 *
	 * @return string[] The always distributed taxonomies.
	 */
	public static function get_always_distributed_taxonomies() {
		$always_distributed_taxonomies = [
			'category', // WordPress core 'category' taxonomy.
			'post_tag', // WordPress core 'post_tag' taxonomy.
		];

		/**
		 * Filters the taxonomies that should always be distributed.
		 *
		 * @param string[] $always_distributed_taxonomies The always distributed taxonomies.
		 */
		return apply_filters( 'newspack_network_content_distribution_always_distributed_taxonomies', $always_distributed_taxonomies );
	}

	/**
	 * Returns a list of taxonomies that should be distributed only if the terms already exist
	 * in the destination site. Terms from these taxonomies will not be created if they don't exist.
	 *
	 * @return string[] Array of taxonomy slugs that should be distributed only if terms exist
	 */
	public static function get_existing_terms_only_taxonomies() {
		$existing_terms_only_taxonomies = [
			'brand', // Newspack Multibranded Sites 'brand' taxonomy.
		];
		/**
		 * Filter the taxonomies that should be distributed only if terms already exist.
		 *
		 * @param array $taxonomies Array of taxonomy slugs.
		 */
		return apply_filters( 'newspack_network_content_distribution_existing_terms_only_taxonomies', $existing_terms_only_taxonomies );
	}

	/**
	 * Get post taxonomy terms for distribution.
	 *
	 * @param \WP_Post $post The post object.
	 * @return array The taxonomy term data.
	 */
	public static function get_post_taxonomy_terms( \WP_Post $post ) {
		$ignored_taxonomies = self::get_ignored_taxonomies();
		$taxonomies         = get_object_taxonomies( $post->post_type, 'objects' );
		$data                = [];
		foreach ( $taxonomies as $taxonomy ) {
			if ( in_array( $taxonomy->name, $ignored_taxonomies, true ) ) {
				continue;
			}
			if ( ! $taxonomy->public && ! in_array( $taxonomy->name, self::get_always_distributed_taxonomies(), true ) ) {
				continue;
			}
			$terms = get_the_terms( $post->ID, $taxonomy->name );
			if ( ! $terms ) {
				$terms = [];
			}

			$data[ $taxonomy->name ] = array_map(
				function( $term ) {
					return [
						'name' => self::recursively_get_term_name( $term ),
						'slug' => $term->slug,
					];
				},
				$terms
			);
		}
		return $data;
	}

	/**
	 * Recursively get the term name.
	 *
	 * @param \WP_Term $term The term.
	 * @return string The term name.
	 */
	public static function recursively_get_term_name( \WP_Term $term ) {
		if ( $term->parent ) {
			return self::recursively_get_term_name( get_term( $term->parent, $term->taxonomy ) ) . self::SEPARATOR . $term->name;
		}
		return $term->name;
	}

	/**
	 * Get or create term IDs.
	 *
	 * Given a term definition created by recursively_get_term_name(),
	 * this function will get the term IDs for the terms based on their names.
	 * If the term does not exist, it will be created, unless the taxonomy is
	 * in the list of taxonomies that should only have terms that already exist.
	 *
	 * @param array  $terms The terms.
	 * @param string $taxonomy The taxonomy.
	 * @return array|WP_Error The term IDs on success, WP_Error on failure.
	 */
	public static function get_or_create_term_ids( $terms, $taxonomy ) {
		$term_ids = [];

		foreach ( $terms as $term_data ) {
			$term_id = self::recursively_get_and_create_term_id( $term_data['name'], $taxonomy );

			if ( is_wp_error( $term_id ) ) {
				return $term_id;
			}

			if ( $term_id ) {
				$term_ids[] = $term_id;
			}
		}

		return $term_ids;
	}

	/**
	 * Recursively get and create term ID.
	 *
	 * Given a term name, this function will recursively get the term ID.
	 * If the term does not exist, it will be created, unless the taxonomy is
	 * in the list of taxonomies that should only have terms that already exist.
	 *
	 * @param string $term_name The term name.
	 * @param string $taxonomy The taxonomy.
	 * @return int|false|WP_Error The term ID on success, false if the term does not exist and should not be created, WP_Error on failure.
	 */
	public static function recursively_get_and_create_term_id( $term_name, $taxonomy ) {

		$term_name_parts = explode( self::SEPARATOR, $term_name );
		$parent = 0;
		$term_id = false;

		foreach ( $term_name_parts as $term_name_part ) {
			$found_terms = get_terms(
				[
					'name'       => $term_name_part,
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'parent'     => $parent,
					'fields'     => 'ids',
				]
			);

			if ( empty( $found_terms ) ) {

				// If the taxonomy is in the list of taxonomies that should only
				// have terms that already exist, skip the term creation.
				if ( in_array( $taxonomy, self::get_existing_terms_only_taxonomies(), true ) ) {
					$term_id = false;
					break;
				}

				$term = wp_insert_term( $term_name_part, $taxonomy, [ 'parent' => $parent ] );
				if ( is_wp_error( $term ) ) {
					return new \WP_Error( 'error_creating_term', 'Failed to insert term ' . $term_name_part . ' for taxonomy ' . $taxonomy . ' with message: ' . $term->get_error_message() );
				}

				$term_id = $term['term_id'];
				$parent = $term_id;
			} else {
				$term_id = $found_terms[0];
				$parent = $term_id;
			}
		}

		return $term_id;
	}
}
