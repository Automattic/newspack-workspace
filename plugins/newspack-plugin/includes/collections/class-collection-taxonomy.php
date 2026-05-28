<?php
/**
 * Collections Taxonomy handler.
 *
 * @package Newspack
 */

namespace Newspack\Collections;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the Collections taxonomy and related operations.
 */
class Collection_Taxonomy {
	use Traits\Hook_Manager;

	/**
	 * Taxonomy for Collections.
	 *
	 * @var string
	 */
	private const TAXONOMY = 'newspack_collection_taxonomy';

	/**
	 * Meta key for storing whether a term is inactive.
	 *
	 * @var string
	 */
	public const INACTIVE_TERM_META_KEY = '_newspack_collection_inactive';

	/**
	 * Get the hooks for collection taxonomy operations.
	 *
	 * @return array {
	 *     Array of hooks with the same structure as the add_action() parameters.
	 *
	 *     @type string   $hook
	 *     @type callable $callback
	 *     @type int      $priority
	 *     @type int      $accepted_args
	 * }
	 */
	protected static function get_hooks() {
		return [
			[ 'created_' . self::get_taxonomy(), [ Sync::class, 'handle_term_created' ], 10, 2 ],
			[ 'edited_' . self::get_taxonomy(), [ Sync::class, 'handle_term_edited' ], 10, 2 ],
			[ 'pre_delete_term', [ Sync::class, 'handle_term_deleted' ], 10, 2 ],
			[ 'get_terms_args', [ __CLASS__, 'filter_inactive_terms' ], 10, 2 ],
		];
	}

	/**
	 * Get the taxonomy for Collections.
	 *
	 * @return string The taxonomy name.
	 */
	public static function get_taxonomy() {
		return self::TAXONOMY;
	}

	/**
	 * Initialize the taxonomy handler.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_taxonomy' ] );
		add_filter( 'newspack_blocks_home_page_block_custom_taxonomies', [ __CLASS__, 'add_collections_taxonomy_to_blocks' ] );
		self::register_hooks();
	}

	/**
	 * Register the Collections taxonomy.
	 */
	public static function register_taxonomy() {
		$labels = [
			'name'          => _x( 'Collections', 'collection taxonomy general name', 'newspack-plugin' ),
			'singular_name' => _x( 'Collection', 'collection taxonomy singular name', 'newspack-plugin' ),
			'search_items'  => __( 'Search Collections', 'newspack-plugin' ),
			'popular_items' => __( 'Popular Collections', 'newspack-plugin' ),
			'all_items'     => __( 'All Collections', 'newspack-plugin' ),
			'view_item'     => __( 'View Collection', 'newspack-plugin' ),
			'edit_item'     => __( 'Edit Collection', 'newspack-plugin' ),
			'update_item'   => __( 'Update Collection', 'newspack-plugin' ),
			'add_new_item'  => __( 'Add New Collection', 'newspack-plugin' ),
			'new_item_name' => __( 'New Collection Name', 'newspack-plugin' ),
			'menu_name'     => _x( 'Collections', 'label for collection menu name', 'newspack-plugin' ),
		];

		$args = [
			'labels'             => $labels,
			'description'        => __( 'Internal taxonomy for associating collections with posts.', 'newspack-plugin' ),
			'public'             => false,
			'show_ui'            => true,
			'show_in_menu'       => false,
			'show_admin_column'  => true,
			'show_in_quick_edit' => false,
			'query_var'          => false,
			'rewrite'            => false,
			'show_in_rest'       => true,
		];

		register_taxonomy( self::get_taxonomy(), [ 'post' ], $args );
	}

	/**
	 * Check if a term is inactive.
	 *
	 * @param int $term_id Term ID.
	 * @return bool True if the term is inactive, false otherwise.
	 */
	public static function is_term_inactive( $term_id ) {
		return (bool) get_term_meta( $term_id, self::INACTIVE_TERM_META_KEY, true );
	}

	/**
	 * Deactivate a term by setting the inactive flag.
	 *
	 * @param int $term_id Term ID.
	 * @return int|bool|\WP_Error Meta ID if the key didn't exist. true on successful update, false on failure or if the value passed to the function is the same as the one that is already in the database. WP_Error when term_id is ambiguous between taxonomies.
	 */
	public static function deactivate_term( $term_id ) {
		/**
		 * Fires before marking a term as inactive.
		 *
		 * @param int $term_id Term ID.
		 */
		do_action( 'newspack_collections_before_deactivating_term', $term_id );

		return update_term_meta( $term_id, self::INACTIVE_TERM_META_KEY, '1' );
	}

	/**
	 * Reactivate a term by removing the inactive flag.
	 *
	 * @param int $term_id Term ID.
	 * @return bool True on success, false on failure.
	 */
	public static function reactivate_term( $term_id ) {
		/**
		 * Fires before reactivating a term.
		 *
		 * @param int $term_id Term ID.
		 */
		do_action( 'newspack_collections_before_reactivating_term', $term_id );

		return delete_term_meta( $term_id, self::INACTIVE_TERM_META_KEY );
	}

	/**
	 * Check if a term exists, even if it's inactive.
	 *
	 * @param int $term_id Term ID.
	 * @return mixed Returns null if the term does not exist. Returns an array of the term ID and the term taxonomy ID if the taxonomy is specified and the pairing exists. Returns 0 if term ID 0 is passed to the function.
	 */
	public static function term_exists( $term_id ) {
		// Remove the filter to check if term is in the database.
		self::unregister_hooks();
		$result = term_exists( $term_id, self::get_taxonomy() );
		self::register_hooks();

		return $result;
	}

	/**
	 * Filter out inactive terms from queries.
	 *
	 * @param array    $args       An array of get_terms() arguments.
	 * @param string[] $taxonomies Array of taxonomy names.
	 * @return array Modified arguments.
	 */
	public static function filter_inactive_terms( $args, $taxonomies ) {
		if ( ! in_array( self::get_taxonomy(), $taxonomies, true ) ) {
			return $args;
		}

		$meta_query = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : [];

		$meta_query[] = [
			'relation' => 'OR',
			[
				'key'     => self::INACTIVE_TERM_META_KEY,
				'compare' => 'NOT EXISTS',
			],
			[
				'key'     => self::INACTIVE_TERM_META_KEY,
				'value'   => '1',
				'compare' => '!=',
				'type'    => 'CHAR',
			],
		];

		$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query

		return $args;
	}

	/**
	 * Find a term by name with optional meta conditions.
	 *
	 * @param string $term_name The name of the term to find.
	 * @param array  $meta_conditions Optional. Array of meta conditions to check. Default empty array.
	 * @return int[]|WP_Error Array of term IDs on success, WP_Error on failure.
	 */
	public static function find_term_by_name( $term_name, $meta_conditions = [] ) {
		$args = [
			'taxonomy'   => self::get_taxonomy(),
			'hide_empty' => false,
			'name'       => $term_name,
			'fields'     => 'ids',
			'number'     => 1,
		];

		if ( ! empty( $meta_conditions ) ) {
			$args['meta_query'] = $meta_conditions; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		return get_terms( $args );
	}

	/**
	 * Add the Collections taxonomy to the custom taxonomies array.
	 *
	 * @param array $custom_taxonomies Array of custom taxonomies.
	 * @return array Modified array of custom taxonomies.
	 */
	public static function add_collections_taxonomy_to_blocks( $custom_taxonomies ) {
		$collections_taxonomy = [
			'slug'  => self::TAXONOMY,
			'label' => __( 'Collections', 'newspack-plugin' ),
		];

		$point_of_insertion = null;
		// Loop through the taxonomies; confirm Collections doesn't already exist, and grab the index of Collection Sections if it's there.
		foreach ( $custom_taxonomies as $index => $tax ) {
			if ( isset( $tax['slug'] ) ) {
				if ( $tax['slug'] === $collections_taxonomy['slug'] ) {
					return $custom_taxonomies;
				}
				if ( $tax['slug'] === Collection_Section_Taxonomy::get_taxonomy() ) {
					$point_of_insertion = $index;
				}
			}
		}

		// If Collection Sections exists in the taxonomy filters, insert Collections before it. If not, use the default insertion point.
		if ( null !== $point_of_insertion ) {
			array_splice( $custom_taxonomies, $point_of_insertion, 0, [ $collections_taxonomy ] );
		} else {
			$custom_taxonomies[] = $collections_taxonomy;
		}

		return $custom_taxonomies;
	}
}
