<?php
/**
 * Newspack Network authorship filters.
 *
 * @package Newspack
 */

namespace Newspack_Network\Distributor_Customizations;

use Distributor\DistributorPost;
use Newspack\Data_Events;
use Newspack_Network\Debugger;
use Newspack_Network\User_Update_Watcher;
use Newspack_Network\Utils\Users as User_Utils;

/**
 * Class to handle authorshipt filters
 *
 * This class is responsible for filtering the authorship data that is publicly displayed in the site using the distributed authors and including CAP's guest authors
 */
class Authorship_Filters {

	/**
	 * Initializes the class
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'get_coauthors', [ __CLASS__, 'filter_coauthors' ], 10, 2 );
		add_filter( 'newspack_author_bio_name', [ __CLASS__, 'newspack_author_bio_name' ], 10, 3 );
		add_filter( 'author_link', [ __CLASS__, 'author_link' ], 20, 3 );
	}

	/**
	 * Filters the coauthors of a post to include the distributed authors and CAP's guest authors
	 *
	 * @param array $coauthors Array of coauthors.
	 * @param int   $post_id   Post ID.
	 * @return array
	 */
	public static function filter_coauthors( $coauthors, $post_id ) {
		if ( ! class_exists( 'Distributor\DistributorPost' ) ) {
			return $coauthors;
		}

		// We don't want to filter authors on admin, as it might break things.
		if ( is_admin() ) {
			return $coauthors;
		}

		// Only filter posts that are still linked to the original post.
		// We check the dt_unlinked post meta to determine linked status.
		// See: https://github.com/10up/distributor/blob/1f180d74db804a057f7331ce62338e571dd73350/docs/post-meta.md?plain=1#L38.
		if ( get_post_meta( $post_id, 'dt_unlinked', true ) ) {
			return $coauthors;
		}

		$distributed_authors = get_post_meta( $post_id, 'newspack_network_authors', true );

		// If anything, at any point, goes wrong with the distributed authors, we return the original coauthors.
		if ( ! $distributed_authors ) {
			return $coauthors;
		}

		$filtered_coauthors = [];

		foreach ( $distributed_authors as $distributed_author ) {
			if ( ! isset( $distributed_author['type'] ) ) {
				return $coauthors;
			}

			if ( 'guest_author' === $distributed_author['type'] ) {

				// This removes the author URL from the guest author.
				$distributed_author['user_nicename'] = '';
				$distributed_author['ID']            = -2;

				$filtered_coauthors[] = (object) $distributed_author;

			} elseif ( 'wp_user' === $distributed_author['type'] ) {
				$user = get_user_by( 'email', $distributed_author['user_email'] );
				if ( ! is_a( $user, 'WP_User' ) ) {
					return $coauthors;
				}
				$filtered_coauthors[] = $user;
			}
		}

		return $filtered_coauthors;
	}

	/**
	 * Add job title for guest authors in the author bio.
	 *
	 * @param string $author_name The author name.
	 * @param int    $author_id The author ID.
	 * @param object $author The author object.
	 */
	public static function newspack_author_bio_name( $author_name, $author_id, $author = null ) {
		if ( empty( $author->type ) || 'guest_author' !== $author->type ) {
			return $author_name;
		}

		if ( $author && ! empty( $author->newspack_job_title ) ) {
			$author_name .= '<span class="author-job-title">' . $author->newspack_job_title . '</span>';
		}

		return $author_name;
	}

	/**
	 * Filter the author link for guest authors.
	 *
	 * @param string $link The author link.
	 * @param int    $author_id The author ID.
	 * @param string $author_nicename The author nicename.
	 * @return string
	 */
	public static function author_link( $link, $author_id, $author_nicename ) {
		if ( -2 === $author_id && empty( $author_nicename ) ) {
			$link = '#';
		}

		return $link;
	}
}
