<?php
/**
 * Newspack Network Distributor Yoast Primary Category on Content Distribution
 *
 * @package newspack-network
 */

namespace Newspack_Network\Content_Distribution;

/**
 * Class to handle Yoast's primary category metadata distribution
 */
class Yoast_Primary_Cat {

	/**
	 * The meta name for the primary category slug.
	 *
	 * @var string
	 */
	const PRIMARY_CAT_NAME_META_NAME = '_newspack_network_primary_cat_name';

	/**
	 * Initialize the class.
	 */
	public static function init() {
		add_filter( 'newspack_network_distributed_post_meta', [ __CLASS__, 'filter_outgoing_post' ], 10, 2 );
		add_action( 'newspack_network_incoming_post_inserted', [ __CLASS__, 'after_incoming_post_inserted' ], 10, 3 );
	}

	/**
	 * Filter the outgoing post meta.
	 *
	 * @param array    $meta The post meta.
	 * @param \WP_Post $post The post object.
	 * @return array
	 */
	public static function filter_outgoing_post( $meta, $post ) {

		if ( ! class_exists( 'WPSEO_Primary_Term' ) ) {
			return $meta;
		}


		$primary_term = new \WPSEO_Primary_Term( 'category', $post->ID );
		$category_id  = $primary_term->get_primary_term();
		if ( $category_id ) {
			$category = get_term( $category_id );
			if ( $category instanceof \WP_Term ) {
				$meta[ self::PRIMARY_CAT_NAME_META_NAME ] = [ Taxonomy_Terms::recursively_get_term_name( $category ) ];
			}
		}

		return $meta;
	}

	/**
	 * After the incoming post is inserted.
	 *
	 * @param int   $post_id The post ID.
	 * @param bool  $is_linked Whether the post is linked.
	 * @param array $payload The payload.
	 */
	public static function after_incoming_post_inserted( $post_id, $is_linked, $payload ) {

		if ( ! class_exists( 'WPSEO_Primary_Term' ) ) {
			return;
		}

		if ( ! $is_linked ) {
			return;
		}

		$primary_cat_name = get_post_meta( $post_id, self::PRIMARY_CAT_NAME_META_NAME, true );
		if ( ! $primary_cat_name ) {
			return;
		}

		$term_id = Taxonomy_Terms::recursively_get_and_create_term_id( $primary_cat_name, 'category' );
		if ( is_wp_error( $term_id ) ) {
			return;
		}

		$primary_term = new \WPSEO_Primary_Term( 'category', $post_id );
		$primary_term->set_primary_term( $term_id );
	}
}
