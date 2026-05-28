<?php
/**
 * Cache manager class.
 *
 * @package Newspack
 */

namespace Newspack\Collections;

defined( 'ABSPATH' ) || exit;

/**
 * Class for Collections cache operations.
 */
class Cache {

	public const CACHE_GROUP     = 'newspack_collections';
	public const YEARS_CACHE_KEY = 'available_years';
	public const POSTS_CACHE_KEY = 'posts_in_collection_';

	/**
	 * Initialize cache invalidation hooks.
	 */
	public static function init() {
		// Clear cache when posts are modified (handles both collections and regular posts).
		add_action( 'save_post', [ __CLASS__, 'clear_cache_on_post_change' ] );
		add_action( 'delete_post', [ __CLASS__, 'clear_cache_on_post_change' ] );
		add_action( 'wp_trash_post', [ __CLASS__, 'clear_cache_on_post_change' ] );
		add_action( 'untrash_post', [ __CLASS__, 'clear_cache_on_post_change' ] );

		// Clear cache when collection taxonomies are created, edited, or deleted.
		add_action( 'create_term', [ __CLASS__, 'clear_cache_on_term_change' ], 10, 3 );
		add_action( 'edit_term', [ __CLASS__, 'clear_cache_on_term_change' ], 10, 3 );
		add_action( 'delete_term', [ __CLASS__, 'clear_cache_on_term_change' ], 10, 3 );

		// Clear cache when term relationships change (posts assigned to collections).
		add_action( 'set_object_terms', [ __CLASS__, 'clear_cache_on_term_relationship_change' ], 10, 4 );

		// Clear cache when post meta that affects collection posts changes.
		add_action( 'updated_post_meta', [ __CLASS__, 'clear_cache_on_meta_change' ], 10, 3 );
		add_action( 'added_post_meta', [ __CLASS__, 'clear_cache_on_meta_change' ], 10, 4 );
		add_action( 'deleted_post_meta', [ __CLASS__, 'clear_cache_on_meta_change' ], 10, 4 );
	}

	/**
	 * Get versioned cache key for collection posts.
	 *
	 * @param int $collection_id Collection post ID.
	 * @return string Versioned cache key.
	 */
	public static function get_posts_cache_key( $collection_id ) {
		return self::POSTS_CACHE_KEY . $collection_id . ':' . wp_cache_get_last_changed( self::CACHE_GROUP );
	}

	/**
	 * Clear cache entries.
	 *
	 * @param string   $type          Cache type: 'years', 'posts', 'all'. Default is 'all'.
	 * @param int|null $collection_id Optional collection ID for specific post cache.
	 */
	public static function clear_cache( $type = 'all', $collection_id = null ) {
		switch ( $type ) {
			case 'years':
				wp_cache_delete( self::YEARS_CACHE_KEY, self::CACHE_GROUP );
				break;
			case 'posts':
				if ( $collection_id ) {
					// Clear specific collection posts cache.
					wp_cache_delete( self::get_posts_cache_key( $collection_id ), self::CACHE_GROUP );
				} else {
					// Clear all collection posts cache by bumping last_changed.
					wp_cache_set_last_changed( self::CACHE_GROUP );
				}
				break;
			case 'all':
				wp_cache_delete( self::YEARS_CACHE_KEY, self::CACHE_GROUP );
				wp_cache_set_last_changed( self::CACHE_GROUP );
				break;
		}
	}

	/**
	 * Clear cache when any post is modified (handles both collection and regular posts).
	 *
	 * @param int $post_id The post ID.
	 */
	public static function clear_cache_on_post_change( $post_id ) {
		$post_type = get_post_type( $post_id );

		// If this is a collection post, clear years cache and specific collection cache.
		if ( Post_Type::get_post_type() === $post_type ) {
			self::clear_cache( 'years' );
			self::clear_cache( 'posts', $post_id );
		} elseif ( 'post' === $post_type ) {
			// For regular posts, clear cache for all collections this post belongs to.
			$collections = Query_Helper::get_post_collections( $post_id );
			foreach ( $collections as $collection ) {
				self::clear_cache( 'posts', $collection );
			}
		}
	}

	/**
	 * Clear cache for taxonomy-related changes.
	 *
	 * @param string    $taxonomy Taxonomy slug.
	 * @param int|array $term_ids Term ID(s) - single int or array of ints.
	 */
	private static function clear_cache_for_taxonomy( $taxonomy, $term_ids ) {
		if ( Collection_Category_Taxonomy::get_taxonomy() === $taxonomy ) {
			self::clear_cache( 'years' );
		} elseif ( Collection_Taxonomy::get_taxonomy() === $taxonomy ) {
			self::clear_cache( 'years' );
			// Clear cache for collections linked to these terms.
			$term_ids = is_array( $term_ids ) ? $term_ids : [ $term_ids ];
			foreach ( $term_ids as $term_id ) {
				$collection_id = Sync::get_collection_linked_to_term( $term_id );
				if ( $collection_id ) {
					self::clear_cache( 'posts', $collection_id );
				}
			}
		} elseif ( Collection_Section_Taxonomy::get_taxonomy() === $taxonomy ) {
			// Section changes affect post organization, clear all collection posts cache.
			self::clear_cache( 'posts' );
		}
	}

	/**
	 * Clear cache when collection taxonomies are modified.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function clear_cache_on_term_change( $term_id, $tt_id, $taxonomy ) {
		self::clear_cache_for_taxonomy( $taxonomy, $term_id );
	}

	/**
	 * Clear cache when term relationships change for collections.
	 *
	 * @param int    $object_id Object ID.
	 * @param array  $terms     An array of object terms.
	 * @param array  $tt_ids    An array of term taxonomy IDs.
	 * @param string $taxonomy  Taxonomy slug.
	 */
	public static function clear_cache_on_term_relationship_change( $object_id, $terms, $tt_ids, $taxonomy ) {
		self::clear_cache_for_taxonomy( $taxonomy, $terms );

		// Clear cache for the affected post (both collection and regular posts).
		self::clear_cache_on_post_change( $object_id );
	}

	/**
	 * Clear cache when post meta that affects collection posts changes.
	 *
	 * @param int    $meta_id    ID of updated metadata entry.
	 * @param int    $object_id  Object ID.
	 * @param string $meta_key   Meta key.
	 */
	public static function clear_cache_on_meta_change( $meta_id, $object_id, $meta_key ) {
		$post_type = get_post_type( $object_id );

		// Clear cache when relevant meta keys change.
		if ( in_array( $post_type, [ 'post', Post_Type::get_post_type() ], true ) && str_contains( $meta_key, Post_Meta::$prefix ) ) {
			self::clear_cache_on_post_change( $object_id );
		}
	}
}
