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
	 * Handles two URL modes for brands:
	 * - "Homepage" mode (_custom_url=yes): brand at site root, e.g. /sports/
	 * - "Default" mode (_custom_url=no): brand under /brand/ prefix, e.g. /brand/lifestyle/
	 *
	 * The "Default" mode requires special handling because other plugins (e.g.
	 * WooCommerce) may register taxonomies with the same "brand" rewrite slug,
	 * causing their rewrite rules to capture /brand/{slug}/ requests. This method
	 * detects when a request was matched to a conflicting taxonomy and re-routes
	 * it to our brand taxonomy when appropriate.
	 *
	 * @param WP $wp The WP object.
	 * @return void
	 */
	public static function parse_request( $wp ) {
		// Handle "Default" mode: detect /brand/{slug}/ captured by a conflicting taxonomy.
		self::maybe_resolve_rewrite_conflict( $wp );

		// Handle "Homepage" mode: detect brand slugs at the site root.
		self::maybe_resolve_root_brand( $wp );
	}

	/**
	 * Resolve rewrite conflicts for the "Default" URL mode.
	 *
	 * When another taxonomy shares the "brand" rewrite slug, its rewrite rules
	 * capture /brand/{slug}/ requests. This checks if the matched slug is
	 * actually a brand term in our taxonomy and re-routes accordingly.
	 *
	 * @param WP $wp The WP object.
	 * @return void
	 */
	private static function maybe_resolve_rewrite_conflict( $wp ) {
		$matched_query = wp_parse_args( $wp->matched_query );

		// Find the query var and slug from a taxonomy whose rewrite slug is "brand".
		$conflict = self::get_conflicting_brand_query_var( $matched_query );
		if ( ! $conflict ) {
			return;
		}

		$term = get_term_by( 'slug', $conflict['slug'], Taxonomy::SLUG );
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		// Skip brands configured for root URL mode — they live at /{slug}/, not
		// /brand/{slug}/, so we should not claim the /brand/ path for them.
		$custom_url = get_term_meta( $term->term_id, Url_Meta::get_key(), true );
		if ( 'yes' === $custom_url ) {
			return;
		}

		// Remove the specific conflicting taxonomy's query var and set ours.
		unset( $wp->query_vars[ $conflict['query_var'] ] );
		$wp->query_vars[ Taxonomy::SLUG ] = $term->slug;
	}

	/**
	 * Get the query var and slug from a conflicting taxonomy match.
	 *
	 * Checks all registered taxonomies for any that use "brand" as their rewrite
	 * slug (other than our own) and returns the matched query var name and term
	 * slug if found.
	 *
	 * @param array $matched_query The parsed matched query args.
	 * @return array|null Array with 'query_var' and 'slug' keys, or null if no conflict.
	 */
	private static function get_conflicting_brand_query_var( $matched_query ) {
		$taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );
		foreach ( $taxonomies as $taxonomy ) {
			if ( Taxonomy::SLUG === $taxonomy->name ) {
				continue;
			}
			// Skip taxonomies without rewrite rules — they cannot conflict with /brand/.
			if ( ! is_array( $taxonomy->rewrite ) ) {
				continue;
			}
			$rewrite_slug = $taxonomy->rewrite['slug'] ?? $taxonomy->name;
			if ( Taxonomy::SLUG !== $rewrite_slug ) {
				continue;
			}
			if ( empty( $taxonomy->query_var ) ) {
				continue;
			}
			if ( ! empty( $matched_query[ $taxonomy->query_var ] ) ) {
				return [
					'query_var' => $taxonomy->query_var,
					'slug'      => $matched_query[ $taxonomy->query_var ],
				];
			}
		}
		return null;
	}

	/**
	 * Resolve brand slugs at the site root ("Homepage" URL mode).
	 *
	 * @param WP $wp The WP object.
	 * @return void
	 */
	private static function maybe_resolve_root_brand( $wp ) {
		$matched_query = wp_parse_args( $wp->matched_query );

		if ( empty( $matched_query['pagename'] ) && empty( $matched_query['name'] ) ) {
			return;
		}

		$pagename = $matched_query['pagename'] ?? $matched_query['name'];

		$terms = get_terms(
			array(
				'taxonomy'   => Taxonomy::SLUG,
				'hide_empty' => false,
				'meta_key'   => Url_Meta::get_key(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
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
