<?php
/**
 * Newspack Network Distributor Yoast Primary Category
 *
 * @package newspack-network
 */

namespace Newspack_Network\Distributor_Customizations;

/**
 * Class to handle Yoast's primary category metadata distribution
 */
class Yoast_Primary_Cat {
	const YOAST_PRIMARY_CAT_META_NAME = '_yoast_wpseo_primary_category';
	const PRIMARY_CAT_SLUG_META_NAME  = 'newspack_network_primary_cat_slug';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_filter( 'dt_push_post_args', [ __CLASS__, 'filter_push_post_args' ], 10, 2 );
		add_filter( 'dt_pull_post_args', [ __CLASS__, 'filter_pull_post_args' ], 10, 3 );
		add_filter( 'dt_subscription_post_args', [ __CLASS__, 'filter_subscription_post_args' ], 10, 2 );
		add_action( 'dt_process_subscription_attributes', [ __CLASS__, 'process_attributes' ], 10, 2 );
		add_action( 'dt_process_distributor_attributes', [ __CLASS__, 'process_attributes' ], 10, 2 );
		add_action( 'dt_pull_post', [ __CLASS__, 'pull_post' ] );
	}

	/**
	 * Send primary category slug to the Node along with post update data.
	 *
	 * @param WP_Post $post The post object.
	 * @param bool    $is_pulling Whether the post is being pulled from the remote site.
	 */
	private static function get_primary_category_slug( $post, $is_pulling = false ) {
		if ( $is_pulling ) {
			// When pulling content, the post will be the remote site post (not on the WP instance that executes this code).
			// The category slug has to be read from the data on the post object.
			if ( ! isset( $post->meta[ self::YOAST_PRIMARY_CAT_META_NAME ] ) ) {
				return;
			}
			$primary_category_id = reset( $post->meta[ self::YOAST_PRIMARY_CAT_META_NAME ] );
			if ( ! $primary_category_id ) {
				return;
			}
			$maybe_primary_categories = array_filter(
				$post->terms['category'],
				function ( $category ) use ( $primary_category_id ) {
					return (int) $category['term_id'] === (int) $primary_category_id;
				}
			);
			$maybe_primary_category   = reset( $maybe_primary_categories );
			if ( $maybe_primary_category ) {
				return $maybe_primary_category['slug'];
			}
		} elseif ( class_exists( 'WPSEO_Primary_Term' ) ) {
			// When pushing, the post will be the post on this site.
			// The category exists on the site which executes this code, so it can be retrieved via Yoast.
			$primary_term = new \WPSEO_Primary_Term( 'category', $post->ID );
			$category_id  = $primary_term->get_primary_term();
			if ( $category_id ) {
				$category = get_term( $category_id );
				return $category->slug;
			}
		}
	}

	/**
	 * Process distributed post attributes after the distribution has completed.
	 *
	 * @param WP_Post $post The post object.
	 */
	public static function process_attributes( $post ) {
		// Fix the primary category.
		$primary_category_slug = get_post_meta( $post->ID, self::PRIMARY_CAT_SLUG_META_NAME, true );
		// Match the category by slug, the IDs might have a clash.
		$found_primary_category = get_term_by( 'slug', $primary_category_slug, 'category' );
		if ( $found_primary_category ) {
			update_post_meta( $post->ID, self::YOAST_PRIMARY_CAT_META_NAME, $found_primary_category->term_id );
		} elseif ( class_exists( '\Newspack\Logger' ) ) {
			\Newspack\Logger::error( __( 'No matching category found on the Hub site.', 'newspack-network' ) );
		}
	}

	/**
	 * Filter the arguments sent to the remote server during a push.
	 *
	 * @param array   $post_body The post data to be sent to the Node.
	 * @param WP_Post $post The post object.
	 */
	public static function filter_push_post_args( $post_body, $post ) {
		$slug = self::get_primary_category_slug( $post );
		if ( $slug ) {
			$post_body['distributor_meta'][ self::PRIMARY_CAT_SLUG_META_NAME ] = $slug;
		}

		return $post_body;
	}

	/**
	 * Filters the arguments passed into wp_insert_post during a pull
	 *
	 * @param array   $post_array The post data to be sent to the Node.
	 * @param int     $remote_id The remote post ID.
	 * @param WP_Post $post The post object.
	 */
	public static function filter_pull_post_args( $post_array, $remote_id, $post ) {
		$slug = self::get_primary_category_slug( $post, true );
		if ( $slug ) {
			$post_array['meta_input'][ self::PRIMARY_CAT_SLUG_META_NAME ] = $slug;
		}

		return $post_array;
	}

	/**
	 * Send primary category slug to the Node when updating a post.
	 *
	 * @param array   $post_body The post data to be sent to the Node.
	 * @param WP_Post $post The post object.
	 */
	public static function filter_subscription_post_args( $post_body, $post ) {
		$slug = self::get_primary_category_slug( $post );
		$post_body['post_data']['distributor_meta'][ self::PRIMARY_CAT_SLUG_META_NAME ] = $slug;
		return $post_body;
	}

	/**
	 * After the post is pulled.
	 *
	 * @param int $new_post_id The new post ID.
	 */
	public static function pull_post( $new_post_id ) {
		self::process_attributes( get_post( $new_post_id ) );
	}
}
