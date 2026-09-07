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
	 * Claim /brand/{slug}/ back when another taxonomy's rewrite rule captured it.
	 *
	 * Whichever plugin registers its rewrite rules first wins the path, so on a
	 * site running WooCommerce the brand archive is reachable only if we take the
	 * request back here.
	 *
	 * @param \WP $wp The WP object.
	 * @return void
	 */
	private static function maybe_resolve_rewrite_conflict( \WP $wp ): void {
		$own = get_taxonomy( Taxonomy::SLUG );
		if ( ! $own instanceof \WP_Taxonomy ) {
			return;
		}

		// Read the vars WordPress ended up with rather than the raw rule match:
		// the `request` filter runs before this hook, so `matched_query` can name
		// a var that is no longer the one being unset below.
		$conflict = self::get_conflicting_brand_query_var( $wp->query_vars, $own );
		if ( ! $conflict ) {
			return;
		}

		$term = get_term_by( 'slug', $conflict['slug'], Taxonomy::SLUG );
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		// A shared rewrite slug is not enough to conclude the URL is ours: it says
		// two taxonomies use the same word, not that they resolve to the same path.
		// We register with no rewrite argument and so inherit `with_front`, while
		// WooCommerce passes `with_front => false`. Under a fronted permalink
		// structure ("/blog/%postname%/") our brands live at /blog/brand/{slug}/
		// and WooCommerce's at /brand/{slug}/ — no collision at all, and claiming
		// the request there would hijack a genuine WooCommerce URL, which
		// redirect_canonical() would then send on to our path.
		//
		// Asking whether the term's own permalink is the path being requested
		// answers that directly. It also settles the "Homepage" URL mode, whose
		// brands link to the site root: /brand/{slug}/ is not their URL, so we
		// leave it with whoever matched it.
		if ( ! self::term_owns_request( $term, $wp ) ) {
			return;
		}

		// A slug held by BOTH taxonomies resolves in the brand's favour. That is a
		// deliberate choice rather than an oversight: on a multibranded site the
		// /brand/ path is the plugin's own URL space, and before this method
		// existed the winner was whichever plugin's rewrite rules were generated
		// first. The WooCommerce archive for such a term stays reachable through
		// its query-var form. Reverse the precedence here if that trade stops
		// being the right one.
		unset( $wp->query_vars[ $conflict['query_var'] ] );
		$wp->query_vars[ self::get_own_query_var( $own ) ] = $term->slug;
	}

	/**
	 * Whether the requested path is the one this term's permalink points at.
	 *
	 * @param \WP_Term $term The brand term.
	 * @param \WP      $wp   The WP object, whose `request` holds the path relative to home.
	 * @return bool
	 */
	private static function term_owns_request( \WP_Term $term, \WP $wp ): bool {
		$link = get_term_link( $term );
		if ( is_wp_error( $link ) ) {
			return false;
		}

		$term_path = trim( (string) wp_parse_url( $link, PHP_URL_PATH ), '/' );
		$home_path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		// `WP::request` is relative to home, so strip the same prefix from the
		// term's absolute path before comparing on a subdirectory install.
		if ( '' !== $home_path && 0 === strpos( $term_path, $home_path . '/' ) ) {
			$term_path = substr( $term_path, strlen( $home_path ) + 1 );
		}

		return '' !== $term_path && $term_path === trim( (string) $wp->request, '/' );
	}

	/**
	 * Find a public taxonomy other than ours that rewrites under our slug and
	 * matched this request.
	 *
	 * The slug comparison is a pre-filter, not the correctness guard — ownership
	 * of the path is what decides, and that is checked by the caller. What this
	 * buys is the term lookup it skips: without it every request carrying any
	 * public taxonomy query var would hit the database to ask whether the slug
	 * happens to name a brand.
	 *
	 * Both sides of the comparison are read from the registered objects, so a
	 * site that filters either rewrite slug still gets the right answer.
	 *
	 * @param array        $query_vars The request's query vars.
	 * @param \WP_Taxonomy $own        Our own registered taxonomy.
	 * @return array|null Array with 'query_var' and 'slug' keys, or null if no conflict.
	 */
	private static function get_conflicting_brand_query_var( array $query_vars, \WP_Taxonomy $own ): ?array {
		$own_rewrite_slug = is_array( $own->rewrite ) ? ( $own->rewrite['slug'] ?? $own->name ) : $own->name;

		foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $taxonomy ) {
			if ( $own->name === $taxonomy->name ) {
				continue;
			}
			// A taxonomy with no rewrite array generates no rules, so it cannot
			// have matched a pretty URL.
			if ( ! is_array( $taxonomy->rewrite ) ) {
				continue;
			}
			$rewrite_slug = $taxonomy->rewrite['slug'] ?? $taxonomy->name;
			if ( $own_rewrite_slug !== $rewrite_slug ) {
				continue;
			}
			if ( empty( $taxonomy->query_var ) ) {
				continue;
			}
			if ( ! empty( $query_vars[ $taxonomy->query_var ] ) ) {
				return [
					'query_var' => $taxonomy->query_var,
					'slug'      => $query_vars[ $taxonomy->query_var ],
				];
			}
		}
		return null;
	}

	/**
	 * Our taxonomy's query var name.
	 *
	 * `query_var => true` is normalized to the taxonomy name at registration, but
	 * a site can register a different string, and the var we set has to be the
	 * one WP_Query will read.
	 *
	 * @param \WP_Taxonomy $own Our own registered taxonomy.
	 * @return string
	 */
	private static function get_own_query_var( \WP_Taxonomy $own ): string {
		return is_string( $own->query_var ) && '' !== $own->query_var ? $own->query_var : $own->name;
	}

	/**
	 * Resolve brand slugs at the site root ("Homepage" URL mode).
	 *
	 * @param WP $wp The WP object.
	 * @return void
	 */
	private static function maybe_resolve_root_brand( $wp ) {
		if ( empty( $wp->matched_query ) ) {
			return;
		}
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
