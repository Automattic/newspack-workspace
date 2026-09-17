<?php
/**
 * Newspack Multibranded site taxonomy.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Customizations;

use Newspack_Multibranded_Site\Meta\Url as Url_Meta;
use Newspack_Multibranded_Site\Taxonomy;

/**
 * Class to handle the Url Customization
 */
class Url {

	/**
	 * Initializes
	 */
	public static function init() {
		add_action( 'parse_request', [ __CLASS__, 'parse_request' ] );
		add_filter( 'pre_term_link', [ __CLASS__, 'pre_term_link' ], 10, 3 );
	}

	/**
	 * Parse the request
	 *
	 * @param WP $wp The WP object.
	 * @return void
	 */
	public static function parse_request( $wp ) {
		$matched_query = wp_parse_args( $wp->matched_query );

		if ( empty( $matched_query['pagename'] ) && empty( $matched_query['name'] ) ) {
			return;
		}

		$pagename = $matched_query['pagename'] ?? $matched_query['name'];

		$terms = get_terms(
			array(
				'taxonomy'   => Taxonomy::SLUG,
				'hide_empty' => false,
				'meta_key'   => Url_Meta::get_key(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value' => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		foreach ( $terms as $term ) {
			if ( $term->slug === $pagename ) {
				if ( isset( $wp->query_vars['name'] ) ) {
					unset( $wp->query_vars['name'] );
				}
				if ( isset( $wp->query_vars['pagename'] ) ) {
					unset( $wp->query_vars['pagename'] );
				}
				if ( isset( $wp->query_vars['page'] ) ) {
					unset( $wp->query_vars['page'] );
				}

				$wp->query_vars[ Taxonomy::SLUG ] = $term->slug;
				break;
			}
		}
	}

	/**
	 * Make sure the term link is the slug if the custom url is set to yes
	 *
	 * @param string  $termlink The term link.
	 * @param WP_Term $term The term object.
	 * @return string
	 */
	public static function pre_term_link( $termlink, $term ) {
		if ( Taxonomy::SLUG !== $term->taxonomy ) {
			return $termlink;
		}

		$custom_url = get_term_meta( $term->term_id, Url_Meta::get_key(), true );
		if ( 'yes' === $custom_url ) {
			$termlink = $term->slug;
		}

		return $termlink;
	}
}
