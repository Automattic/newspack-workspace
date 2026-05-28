<?php
/**
 * Newspack Sponsors theme helpers.
 *
 * Functions that can be called from themes to get sponsor info.
 *
 * @package Newspack_Sponsors
 */

namespace Newspack_Sponsors;

use Newspack_Sponsors\Core;
use Newspack_Sponsors\Settings;
use WP_Error;

/**
 * Get all sponsors associated with the given ID. Can be a post or term ID.
 * All params are optional, and the function will attempt to guess the $id if
 * not provided.
 *
 * @param int|null    $id    ID of the post or archive term to get sponsors for.
 *                           If not provided, we will try to guess based on context.
 * @param string|null $scope Scope of the sponsors to get. Can be 'native' or
 *                           'underwritten'. If provided, only sponsors with the
 *                           matching scope will be returned. If not, all sponsors
 *                           will be returned regardless of scope.
 * @param string|null $type  Type of the $id given: 'post' or 'archive'. If not
 *                           provided, we will try to guess based on context.
 * @param array       $logo_options Optional array of logo options. Valid options:
 *                                  maxwidth: max width of the logo image, in pixels.
 *                                  maxheight: max height of the logo image, in pixels.
 *
 * @return array|bool Array of associated sponsors, or false if none.
 */
function get_all_sponsors( $id = null, $scope = null, $type = null, $logo_options = [] ) {
	$sponsors = false;

	// Bail early if we don't have any sponsors.
	if ( ! site_has_sponsors() ) {
		return $sponsors;
	}

	// If no type given, try to guess based on the current page context.
	if ( null === $type ) {
		$post_types = apply_filters(
			'newspack_sponsors_post_types',
			[ 'post', 'page' ]
		);

		if ( is_singular( $post_types ) ) {
			$type = 'post';
		} elseif ( is_archive() ) {
			$type = 'archive';
		}
	}

	if ( 'post' === $type ) {
		$sponsors = get_sponsors_for_post( $id, $scope, $logo_options );
	} elseif ( 'archive' === $type ) {
		$sponsors = get_sponsors_for_archive( $id, $scope, $logo_options );
	}

	return $sponsors;
}

/**
 * Checks whether the current site has any published sponsors.
 * If not, lets us short-circuit the helper functions to avoid unnecessary queries.
 */
function site_has_sponsors() {
	return 0 < wp_count_posts( Core::NEWSPACK_SPONSORS_CPT )->publish;
}

/**
 * Get sponsors associated with the given post ID.
 *
 * @param int         $post_id ID for the post to look up (optional).
 * @param string|null $scope   Scope of the sponsors to get: 'native'|'underwritten'.
 * @param array       $logo_options Optional array of logo options. Valid options:
 *                                  maxwidth: max width of the logo image, in pixels.
 *                                  maxheight: max height of the logo image, in pixels.
 * @return array|bool|WP_Error Array of sponsor objects associated with the
 *                             post, false if we can't find a post with given
 *                             $post_id or any sponsors for it, or WP_Error if
 *                             no $post_id given and we're not on a post page.
 */
function get_sponsors_for_post( $post_id = null, $scope = null, $logo_options = [] ) {
	// Bail early if we don't have any sponsors.
	if ( ! site_has_sponsors() ) {
		return false;
	}

	$post_types = apply_filters(
		'newspack_sponsors_post_types',
		[ 'post', 'page' ]
	);

	if ( null === $post_id ) {
		if ( ! is_singular( $post_types ) ) {
			return new WP_Error(
				'newspack-sponsors__is_not_post',
				__( 'Please provide a $post_id if not invoking within a single post.' )
			);
		}

		$post_id = get_the_ID();
	}

	$post = get_post( $post_id );

	// Return false if there's no post for this $post_id.
	if ( empty( $post ) ) {
		return false;
	}

	// Sponsors can't sponsor other sponsors.
	if ( Core::NEWSPACK_SPONSORS_CPT === $post->post_type ) {
		return false;
	}

	// Memoization.
	static $cache = [];
	$hash         = md5( (string) $post_id . (string) $scope . wp_json_encode( $logo_options ) );
	if ( isset( $cache[ $hash ] ) ) {
		return $cache[ $hash ];
	}

	$sponsors        = [];
	$direct_sponsors = get_the_terms( $post_id, Core::NEWSPACK_SPONSORS_TAX );
	$categories      = get_the_category( $post_id );
	$tags            = get_the_tags( $post_id );

	// Get sponsors directly assigned to the post.
	if ( is_array( $direct_sponsors ) ) {
		foreach ( $direct_sponsors as $direct_sponsor ) {
			$sponsor_post = get_related_post( $direct_sponsor->slug );

			if ( ! empty( $sponsor_post ) ) {
				$sponsor_object = convert_post_to_sponsor( $sponsor_post, 'direct', $logo_options );

				if ( null === $scope || $scope === $sponsor_object['sponsor_scope'] ) {
					$sponsors[] = $sponsor_object;
				}
			}
		}
	}

	// Get sponsors for the post's categories, if any.
	$category_sponsors = get_sponsor_posts_for_terms( $categories );

	if ( is_array( $category_sponsors ) ) {
		foreach ( $category_sponsors as $category_sponsor ) {
			// Don't add this sponsor if it's already assigned as a different type.
			if ( true === is_duplicate_sponsor( $sponsors, $category_sponsor->ID ) ) {
				continue;
			}

			$hide_term_sponsor = get_post_meta( $category_sponsor->ID, 'newspack_sponsor_only_direct', true );

			// Don't add if sponsor is set to show only as direct.
			if ( empty( $hide_term_sponsor ) ) {
				$sponsor_object = convert_post_to_sponsor( $category_sponsor, 'category', $logo_options );

				if ( null === $scope || $scope === $sponsor_object['sponsor_scope'] ) {
					$sponsors[] = $sponsor_object;
				}
			}
		}
	}

	// Get sponsors for the post's tags, if any.
	$tag_sponsors = get_sponsor_posts_for_terms( $tags );

	if ( is_array( $tag_sponsors ) ) {
		foreach ( $tag_sponsors as $tag_sponsor ) {
			// Don't add this sponsor if it's already assigned as a different type.
			if ( true === is_duplicate_sponsor( $sponsors, $tag_sponsor->ID ) ) {
				continue;
			}

			$hide_term_sponsor = get_post_meta( $tag_sponsor->ID, 'newspack_sponsor_only_direct', true );

			// Don't add if sponsor is set to show only as direct.
			if ( empty( $hide_term_sponsor ) ) {
				$sponsor_object = convert_post_to_sponsor( $tag_sponsor, 'tag', $logo_options );

				if ( null === $scope || $scope === $sponsor_object['sponsor_scope'] ) {
					$sponsors[] = $sponsor_object;
				}
			}
		}
	}

	if ( 0 === count( $sponsors ) ) {
		$sponsors = false;
	}

	$cache[ $hash ] = $sponsors;

	return $sponsors;
}

/**
 * Get sponsors associated with the given term ID.
 *
 * @param int         $term_id ID for the post to look up (optional).
 * @param string|null $scope   Scope of the sponsors to get: 'native'|'underwritten'.
 * @param array       $logo_options Optional array of logo options. Valid options:
 *                                  maxwidth: max width of the logo image, in pixels.
 *                                  maxheight: max height of the logo image, in pixels.
 * @return array|bool|WP_Error Array of sponsor objects associated with the
 *                             term, false if we can't find a term with given
 *                             $term_id or any sponsors for it, or WP_Error if
 *                             no $term_id given and we're not on a term archive.
 */
function get_sponsors_for_archive( $term_id = null, $scope = null, $logo_options = [] ) {
	// Bail early if we don't have any sponsors.
	if ( ! site_has_sponsors() ) {
		return false;
	}

	if ( null === $term_id ) {
		if ( ! is_archive() ) {
			return new WP_Error(
				'newspack-sponsors__is_not_archive',
				__( 'Please provide a $term_id if not invoking within a term archive page.', 'newspack-sponsors' )
			);
		}

		$term = get_queried_object();
	} else {
		$term = get_term_by( 'term_taxonomy_id', $term_id );
	}

	// Return false if there's no term for this $term_id.
	if ( empty( $term ) ) {
		return false;
	}

	if ( ! property_exists( $term, 'taxonomy' ) ) {
		return false;
	}

	$sponsors      = [];
	$type          = 'category' === $term->taxonomy ? 'category' : 'tag';
	$term_sponsors = get_sponsor_posts_for_terms( [ $term ] );

	if ( is_array( $term_sponsors ) ) {
		foreach ( $term_sponsors as $term_sponsor ) {
			$sponsor_object = convert_post_to_sponsor( $term_sponsor, $type, $logo_options );

			if ( null === $scope || $scope === $sponsor_object['sponsor_scope'] ) {
				$sponsors[] = $sponsor_object;
			}
		}
	}

	if ( 0 === count( $sponsors ) ) {
		return false;
	}

	return $sponsors;
}

/**
 * Check the given $id against $sponsors to see if it exists in the array.
 *
 * @param array $sponsors Array of sponsor objects to check for dupes.
 * @param int   $id Sponsor ID to check whether it's a dupe.
 * @return boolean Whether or not the ID is already in the $sponsors array.
 */
function is_duplicate_sponsor( $sponsors, $id ) {
	$duplicates = array_filter(
		$sponsors,
		function( $sponsor ) use ( $id ) {
			return $sponsor['sponsor_id'] === $id;
		}
	);

	return 0 < count( $duplicates );
}

/**
 * Get a post for the given shadow taxonomy term by term slug.
 *
 * @param string $slug Term slug to use for looking up post.
 * @return array|bool Post object for the related post, or false.
 */
function get_related_post( $slug ) {
	$related_post = new \WP_Query(
		[
			'is_sponsors'    => 1,
			'post_type'      => Core::NEWSPACK_SPONSORS_CPT,
			'posts_per_page' => 1,
			'post_status'    => 'publish',
			'name'           => $slug,
			'no_found_rows'  => true,
		]
	);

	if ( empty( $related_post->posts ) || is_wp_error( $related_post ) ) {
		return false;
	}

	return $related_post->posts[0];
}

/**
 * Get all sponsors who are associated with the given terms.
 *
 * @param array $terms Array of term objects to look up.
 * @return array|bool Array of sponsor post objects, if any, or false.
 */
function get_sponsor_posts_for_terms( $terms ) {
	if ( empty( $terms ) ) {
		return false;
	}

	$tax_query_args = [];

	foreach ( $terms as $term ) {
		if ( ! empty( $term->taxonomy ) && ! empty( $term->term_id ) ) {
			$tax_query_args[] = [
				'taxonomy'         => $term->taxonomy,
				'field'            => 'term_id',
				'terms'            => $term->term_id,
				'include_children' => false,
			];
		}
	}

	// No need to run query if there are no valid terms to query.
	if ( empty( $tax_query_args ) ) {
		return false;
	}

	$tax_query_args['relation'] = 'OR';

	$sponsor_posts = new \WP_Query(
		[
			'is_sponsors'    => 1,
			'post_type'      => Core::NEWSPACK_SPONSORS_CPT,
			'posts_per_page' => 100,
			'post_status'    => 'publish',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			'tax_query'      => $tax_query_args,
		]
	);

	if ( empty( $sponsor_posts->posts ) || is_wp_error( $sponsor_posts ) ) {
		return false;
	}

	return $sponsor_posts->posts;
}

/**
 * Get all sponsored terms.
 * 
 * @return array|boolean An associative array keyed by taxonomy name with an array of sponsored term IDs for each, or false if no sponsored terms.
 */
function get_all_sponsored_terms() {
	$taxonomies = \get_object_taxonomies( Core::NEWSPACK_SPONSORS_CPT );
	if ( empty( $taxonomies ) ) {
		return false;
	}
	
	$tax_query_args = array_map(
		function( $taxonomy ) {
			return [
				'taxonomy' => $taxonomy,
				'operator' => 'EXISTS',
			];
		},
		$taxonomies
	);

	$tax_query_args['relation'] = 'OR';
	$sponsors_with_terms        = new \WP_Query(
		[
			'is_sponsors'    => 1,
			'fields'         => 'ids',
			'post_type'      => Core::NEWSPACK_SPONSORS_CPT,
			'posts_per_page' => 100,
			'post_status'    => 'publish',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			'tax_query'      => $tax_query_args,
		]
	);

	if ( empty( $sponsors_with_terms->posts ) ) {
		return false;
	}

	$sponsored_terms = \get_terms(
		[
			'taxonomy'   => $taxonomies,
			'object_ids' => $sponsors_with_terms->posts,
		]
	);

	return array_reduce(
		$sponsored_terms,
		function( $acc, $term ) {
			if ( ! isset( $acc[ $term->taxonomy ] ) ) {
				$acc[ $term->taxonomy ] = [];
			}

			$acc[ $term->taxonomy ][] = $term->term_id;
			return $acc;
		},
		[]
	);
}

/**
 * Formats a post object into a sponsor object, for ease of theme developer use.
 *
 * @param array  $post Post object to convert.
 * @param string $type Type of sponsorship: direct, category, or tag?. Default: 'direct'.
 * @param array  $logo_options Optional array of logo options. Valid options:
 *                             maxwidth: max width of the logo image, in pixels.
 *                             maxheight: max height of the logo image, in pixels.
 * @return array|bool Sponsor object, or false.
 */
function convert_post_to_sponsor( $post, $type = 'direct', $logo_options = [] ) {
	if ( empty( $post ) ) {
		return false;
	}

	$sponsor_sitewide_settings = Settings::get_settings();

	$sponsor_byline                = get_post_meta( $post->ID, 'newspack_sponsor_byline_prefix', true );
	$sponsor_url                   = get_post_meta( $post->ID, 'newspack_sponsor_url', true );
	$sponsor_flag                  = get_post_meta( $post->ID, 'newspack_sponsor_flag_override', true );
	$sponsor_scope                 = get_post_meta( $post->ID, 'newspack_sponsor_sponsorship_scope', true );
	$sponsor_byline_display        = get_post_meta( $post->ID, 'newspack_sponsor_native_byline_display', true );
	$sponsor_category_display      = get_post_meta( $post->ID, 'newspack_sponsor_native_category_display', true );
	$sponsor_underwriter_style     = get_post_meta( $post->ID, 'newspack_sponsor_underwriter_style', true );
	$sponsor_underwriter_placement = get_post_meta( $post->ID, 'newspack_sponsor_underwriter_placement', true );
	$sponsor_disclaimer            = get_post_meta( $post->ID, 'newspack_sponsor_disclaimer_override', true );
	$sponsor_logo                  = get_logo_info( $post->ID, $logo_options );

	// Check for single-sponsor overrides, default to site-wide options.
	if ( empty( $sponsor_byline ) ) {
		$sponsor_byline = $sponsor_sitewide_settings['byline'];
	}
	if ( empty( $sponsor_flag ) ) {
		$sponsor_flag = $sponsor_sitewide_settings['flag'];
	}
	if ( empty( $sponsor_disclaimer ) ) {
		$sponsor_disclaimer = str_replace( '[sponsor name]', $post->post_title, $sponsor_sitewide_settings['disclaimer'] );
	}

	$sponsor = [
		'sponsor_type'       => $type,
		'sponsor_id'         => $post->ID,
		'sponsor_name'       => $post->post_title,
		'sponsor_slug'       => $post->post_name,
		'sponsor_blurb'      => $post->post_content,
		'sponsor_url'        => $sponsor_url,
		'sponsor_byline'     => $sponsor_byline,
		'sponsor_logo'       => $sponsor_logo,
		'sponsor_flag'       => $sponsor_flag,
		'sponsor_scope'      => ! empty( $sponsor_scope ) ? $sponsor_scope : 'native', // Default: native, not underwritten.
		'sponsor_disclaimer' => $sponsor_disclaimer,
	];

	if ( 'native' === $sponsor['sponsor_scope'] ) {
		$sponsor['sponsor_byline_display']   = $sponsor_byline_display;
		$sponsor['sponsor_category_display'] = $sponsor_category_display;
	} else {
		$sponsor['sponsor_underwriter_style']     = $sponsor_underwriter_style;
		$sponsor['sponsor_underwriter_placement'] = $sponsor_underwriter_placement;
	}

	return $sponsor;
}

/**
 * Returns scaled down logo sizes based on the provided width and height;
 * this is necessary for AMP.
 *
 * @param int   $sponsor_id ID of the sponsor post to get logo info for.
 * @param array $logo_options Optional array of logo options. Valid options:
 *                            maxwidth: max width of the logo image, in pixels.
 *                            maxheight: max height of the logo image, in pixels.
 */
function get_logo_info( $sponsor_id, $logo_options = [] ) {
	$sponsor_logo = wp_get_attachment_image_src( get_post_thumbnail_id( $sponsor_id ), 'medium' );
	$logo_info    = [];
	$maxwidth     = ! empty( $logo_options['maxwidth'] ) && is_numeric( $logo_options['maxwidth'] ) ? $logo_options['maxwidth'] : 130;
	$maxheight    = ! empty( $logo_options['maxheight'] ) && is_numeric( $logo_options['maxheight'] ) ? $logo_options['maxheight'] : 45;

	if ( ! empty( $sponsor_logo ) ) {
		// Break out src, original width and original height.
		$logo_info['src'] = $sponsor_logo[0];
		$image_width      = $sponsor_logo[1];
		$image_height     = $sponsor_logo[2];

		// Set the max-height, and width based off that to maintain aspect ratio.
		$logo_info['img_height'] = $maxheight;
		$logo_info['img_width']  = ( $image_width / $image_height ) * $logo_info['img_height'];

		// If the new width is too wide, set to the max-width and update height based off that to maintain aspect ratio.
		if ( $maxwidth < $logo_info['img_width'] ) {
			$logo_info['img_width']  = $maxwidth;
			$logo_info['img_height'] = ( $image_height / $image_width ) * $logo_info['img_width'];
		}
	}

	return $logo_info;
}

/**
 * If at least one native sponsor is set to display both sponsors and authors, show the authors.
 *
 * @param array $sponsors Array of sponsors.
 *
 * @return boolean True if we should display both sponsors and categories, false if we should display only sponsors.
 */
function newspack_display_sponsors_and_authors( $sponsors ) {
	if ( ! is_array( $sponsors ) ) {
		return false;
	}

	// If the post is set to display author, show it.
	$override = get_post_meta( get_the_ID(), 'newspack_sponsor_native_byline_display', true );
	if ( 'author' === $override ) {
		return true;
	}
	if ( 'sponsor' === $override ) {
		return false;
	}

	return array_reduce(
		$sponsors,
		function( $acc, $sponsor ) {
			if ( isset( $sponsor['sponsor_byline_display'] ) && 'author' === $sponsor['sponsor_byline_display'] ) {
				$acc = true;
			}
			return $acc;
		},
		false
	);
}

/**
 * If at least one native sponsor is set to display both sponsors and categories, show the categories.
 *
 * @param array $sponsors Array of sponsors.
 *
 * @return boolean True if we should display both sponsors and categories, false if we should display only sponsors.
 */
function newspack_display_sponsors_and_categories( $sponsors ) {
	if ( ! is_array( $sponsors ) ) {
		return false;
	}

	// If the post is set to display categories, show them.
	$override = get_post_meta( get_the_ID(), 'newspack_sponsor_native_category_display', true );
	if ( 'category' === $override ) {
		return true;
	}
	if ( 'sponsor' === $override ) {
		return false;
	}

	return array_reduce(
		$sponsors,
		function( $acc, $sponsor ) {
			if ( isset( $sponsor['sponsor_category_display'] ) && 'category' === $sponsor['sponsor_category_display'] ) {
				$acc = true;
			}
			return $acc;
		},
		false
	);
}
