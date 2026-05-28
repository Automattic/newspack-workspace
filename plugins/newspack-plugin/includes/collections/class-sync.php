<?php
/**
 * Collections Sync handler.
 *
 * @package Newspack
 */

namespace Newspack\Collections;

defined( 'ABSPATH' ) || exit;

/**
 * Handles syncing between Collections posts and terms.
 */
class Sync {
	/**
	* Meta key for storing the linked taxonomy term ID in post meta.
	*
	* @var string
	*/
	const LINKED_TERM_META_KEY = '_newspack_collection_term_id';

	/**
	 * Meta key for storing the linked post ID in term meta.
	 *
	 * @var string
	 */
	const LINKED_POST_META_KEY = '_newspack_collection_post_id';

	/**
	 * Link a collection and a term.
	 *
	 * @param int $post_id Post ID.
	 * @param int $term_id Term ID.
	 */
	public static function link_collection_and_term( $post_id, $term_id ) {
		/**
		 * Fires before linking a collection and a term.
		 *
		 * @param int $post_id Post ID.
		 * @param int $term_id Term ID.
		 */
		do_action( 'newspack_collections_before_link_collection_and_term', $post_id, $term_id );

		update_post_meta( $post_id, self::LINKED_TERM_META_KEY, $term_id );
		update_term_meta( $term_id, self::LINKED_POST_META_KEY, $post_id );
	}

	/**
	 * Create a linked term if it doesn't exist.
	 *
	 * @param WP_Post $post Post object.
	 * @return WP_Term|array|WP_Error Term object if exists, array if created, WP_Error on failure.
	 */
	public static function create_linked_term( $post ) {
		$term_name = $post->post_title;
		$term      = get_term_by( 'name', $term_name, Collection_Taxonomy::get_taxonomy() );

		if ( ! $term ) {
			/**
			 * Fires before creating a linked term for a post.
			 *
			 * @param WP_Post $post Post object.
			 */
			do_action( 'newspack_collections_before_create_linked_term', $post );

			Collection_Taxonomy::unregister_hooks();
			$term = wp_insert_term( $term_name, Collection_Taxonomy::get_taxonomy(), [ 'slug' => $post->post_name ] );
			if ( ! is_wp_error( $term ) ) {
				self::link_collection_and_term( $post->ID, $term['term_id'] );
			}
			Collection_Taxonomy::register_hooks();
		}

		return $term;
	}

	/**
	 * Create a linked post if it doesn't exist.
	 *
	 * @param WP_Term $term Term object.
	 * @return int|false Post ID on success, false on failure.
	 */
	public static function create_linked_post( $term ) {
		/**
		 * Fires before creating a collection post for a term.
		 *
		 * @param WP_Term $term Term object.
		 */
		do_action( 'newspack_collections_before_create_linked_post', $term );

		// Create the post using the term's name and slug.
		$post_data = [
			'post_title'  => $term->name,
			'post_name'   => $term->slug,
			'post_type'   => Post_Type::get_post_type(),
			'post_status' => 'draft',
		];

		Post_Type::unregister_hooks();
		$post_id = wp_insert_post( $post_data );
		if ( ! is_wp_error( $post_id ) ) {
			self::link_collection_and_term( $post_id, $term->term_id );
			return $post_id;
		}
		Post_Type::register_hooks();

		return false;
	}

	/**
	 * Sync changes from a collection to a term.
	 *
	 * @param int $post_id Post ID.
	 * @param int $term_id Term ID.
	 * @return bool True on success, false on failure.
	 */
	public static function sync_collection_changes_to_term( $post_id, $term_id ) {
		$post = get_post( $post_id );
		$term = get_term( $term_id, Collection_Taxonomy::get_taxonomy() );

		if ( $term && ! is_wp_error( $term ) ) {
			$term_args = [];
			if ( $term->name !== $post->post_title ) {
				$term_args['name'] = $post->post_title;
			}
			if ( $term->slug !== $post->post_name ) {
				$term_args['slug'] = $post->post_name;
			}
			if ( ! empty( $term_args ) ) {
				/**
				 * Fires before syncing a collection to a term.
				 *
				 * @param int   $term_id   Term ID.
				 * @param int   $post_id   Post ID.
				 * @param array $term_args Term arguments.
				 */
				do_action( 'newspack_collections_before_sync_collection_to_term', $term_id, $post_id, $term_args );

				$updated_term = wp_update_term( $term_id, Collection_Taxonomy::get_taxonomy(), $term_args );
				if ( ! is_wp_error( $updated_term ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Sync changes from a term to a collection.
	 *
	 * @param int $term_id Term ID.
	 * @param int $post_id Post ID.
	 * @return bool True on success, false on failure.
	 */
	public static function sync_term_changes_to_collection( $term_id, $post_id ) {
		$term = get_term( $term_id, Collection_Taxonomy::get_taxonomy() );
		$post = get_post( $post_id );

		if ( ! is_wp_error( $post ) ) {
			$post_args = [];
			if ( $post->post_title !== $term->name ) {
				$post_args['post_title'] = $term->name;
			}
			if ( $post->post_name !== $term->slug ) {
				$post_args['post_name'] = $term->slug;
			}
			if ( ! empty( $post_args ) ) {
				$post_args['ID'] = $post_id;

				/**
				 * Fires before syncing a term to a collection.
				 *
				 * @param int   $post_id   Post ID.
				 * @param int   $term_id   Term ID.
				 * @param array $post_args Post arguments.
				 */
				do_action( 'newspack_collections_before_sync_term_to_collection', $post_id, $term_id, $post_args );

				Post_Type::unregister_hooks();
				$updated_post = wp_update_post( $post_args );
				Post_Type::register_hooks();

				if ( ! is_wp_error( $updated_post ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Handle saving a collection post.
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Post $post    The post object.
	 */
	public static function handle_post_save( $post_id, $post ) {
		// Don't handle auto-saves, revisions, auto-drafts, trashed or post with empty titles.
		if (
			( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ||
			wp_is_post_revision( $post_id ) ||
			in_array( $post->post_status, [ 'auto-draft', 'trash' ], true ) ||
			empty( $post->post_title )
		) {
			return;
		}

		$linked_term_id = self::get_term_linked_to_collection( $post_id );

		if ( $linked_term_id ) {
			self::sync_collection_changes_to_term( $post_id, $linked_term_id );
		} else {
			$meta_conditions = [
				'relation' => 'OR',
				[
					'key'     => self::LINKED_POST_META_KEY,
					'value'   => $post_id,
					'compare' => '=',
				],
				[
					'key'     => self::LINKED_POST_META_KEY,
					'compare' => 'NOT EXISTS',
				],
			];

			$existing_term = Collection_Taxonomy::find_term_by_name( $post->post_title, $meta_conditions );

			if ( empty( $existing_term ) ) {
				self::create_linked_term( $post );
			} else {
				self::link_collection_and_term( $post_id, $existing_term[0] );
			}
		}
	}

	/**
	 * Handle collection post deletion or trashing.
	 *
	 * @param int  $post_id    Post ID.
	 * @param bool $is_trashed Whether this is a trash operation (true) or permanent deletion (false).
	 * @return bool|int|WP_Error True on success, false if term does not exist. Zero on attempted deletion of default Category. WP_Error if the taxonomy does not exist.
	 */
	public static function handle_post_deleted( $post_id, $is_trashed = false ) {
		$post = get_post( $post_id );

		// Check if the post exists and is a collection post.
		if ( ! $post || Post_Type::get_post_type() !== $post->post_type ) {
			return false;
		}

		$linked_term_id = self::get_term_linked_to_collection( $post_id );
		if ( ! $linked_term_id || ! Collection_Taxonomy::term_exists( (int) $linked_term_id ) ) {
			return false;
		}

		if ( $is_trashed ) {
			return Collection_Taxonomy::deactivate_term( $linked_term_id );
		}

		/**
		 * Fires before removing a linked term.
		 *
		 * @param int $post_id Post ID.
		 * @param int $term_id Term ID.
		 */
		do_action( 'newspack_collections_before_removing_linked_term', $post_id, $linked_term_id );

		Collection_Taxonomy::unregister_hooks();
		$result = wp_delete_term( $linked_term_id, Collection_Taxonomy::get_taxonomy() );
		Collection_Taxonomy::register_hooks();

		return $result;
	}

	/**
	 * Handle trashing of a collection post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True on success, false if term does not exist.
	 */
	public static function handle_post_trashed( $post_id ) {
		return self::handle_post_deleted( $post_id, true );
	}

	/**
	 * Handle untrashing of a collection.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True on success, false on failure.
	 */
	public static function handle_post_untrashed( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || Post_Type::get_post_type() !== $post->post_type ) {
			return false;
		}

		$linked_term_id = self::get_term_linked_to_collection( $post_id );
		if ( ! $linked_term_id || ! Collection_Taxonomy::term_exists( (int) $linked_term_id ) ) {
			return false;
		}

		return Collection_Taxonomy::reactivate_term( $linked_term_id );
	}

	/**
	 * Handle creation of a collection post for a newly created term.
	 *
	 * @param int $term_id Term ID.
	 * @return int|false Post ID on success, false on failure.
	 */
	public static function handle_term_created( $term_id ) {
		$term = get_term( $term_id, Collection_Taxonomy::get_taxonomy() );
		if ( ! $term || is_wp_error( $term ) ) {
			return false;
		}

		// Check if term already has a linked post.
		$post_id = self::get_collection_linked_to_term( $term_id );
		if ( $post_id ) {
			return false;
		}

		return self::create_linked_post( $term );
	}

	/**
	 * Handle term edits by syncing changes to the linked collection post.
	 *
	 * @param int $term_id Term ID.
	 * @return bool|void True on success, false on failure. Void if no changes were made.
	 */
	public static function handle_term_edited( $term_id ) {
		$post_id = self::get_collection_linked_to_term( $term_id );
		if ( ! $post_id ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || Post_Type::get_post_type() !== $post->post_type ) {
			return;
		}

		return self::sync_term_changes_to_collection( $term_id, (int) $post_id );
	}

	/**
	 * Handle deletion of a collection term by trashing the associated post.
	 *
	 * @param int    $term     Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return WP_Post|false|null|void Post data on success, false or null on failure. Void if no changes were made.
	 */
	public static function handle_term_deleted( $term, $taxonomy ) {
		if ( Collection_Taxonomy::get_taxonomy() !== $taxonomy ) {
			return;
		}

		$post_id = self::get_collection_linked_to_term( $term );
		if ( ! $post_id ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || Post_Type::get_post_type() !== $post->post_type ) {
			return;
		}

		/**
		 * Fires before removing a linked post.
		 *
		 * @param int $post_id Post ID.
		 * @param int $term_id Term ID.
		 */
		do_action( 'newspack_collections_before_removing_linked_post', $post_id, $term );

		// Trash the post instead of deleting it permanently.
		Post_Type::unregister_hooks();
		$result = wp_trash_post( $post_id );
		Post_Type::register_hooks();

		return $result;
	}

	/**
	 * Get collection ID linked to a specific term.
	 *
	 * @param int $term_id The term ID.
	 * @return int|null Collection ID or null if no collection is linked.
	 */
	public static function get_collection_linked_to_term( $term_id ) {
		$collection_id = get_term_meta( $term_id, self::LINKED_POST_META_KEY, true );
		return $collection_id ? (int) $collection_id : null;
	}

	/**
	 * Get term ID linked to a specific collection.
	 *
	 * @param int $collection_id Collection ID.
	 * @return int|null Term ID or null if no term is linked.
	 */
	public static function get_term_linked_to_collection( $collection_id ) {
		$term_id = get_post_meta( $collection_id, self::LINKED_TERM_META_KEY, true );
		return $term_id ? (int) $term_id : null;
	}
}
