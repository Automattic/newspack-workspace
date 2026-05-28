<?php
/**
 * Newspack Network filters for making guest authors work.
 *
 * @package Newspack
 */

namespace Newspack_Network\Content_Distribution;

use Newspack_Network\Debugger;

/**
 * This class only deals with the "classic" Guest authors CAP has built in, not the Guest Contributors Newspack provides.
 */
class Cap_Guest_Authors {

	/**
	 * Meta key for the "fake lightweight" Guest Authors we keep in post meta.
	 */
	const GUEST_AUTHORS_META_KEY = 'newspack_network_guest_authors_distributed';

	/**
	 * Meta key to keep track of which authors and guest authors are assigned to a post.
	 */
	const ASSIGNED_AUTHORS_META_KEY = 'newspack_network_assigned_authors';

	/**
	 * Go!
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( ! Cap_Authors::is_co_authors_plus_active() ) {
			return;
		}

		if ( ! defined( 'NEWSPACK_ENABLE_CAP_GUEST_AUTHORS' ) || empty( NEWSPACK_ENABLE_CAP_GUEST_AUTHORS ) ) {
			return;
		}

		add_filter( 'newspack_network_content_distribution_ignored_post_meta_keys', [ __CLASS__, 'filter_ignored_post_meta_keys' ], 10, 2 );
		add_filter( 'newspack_network_outgoing_non_wp_user_author', [ __CLASS__, 'filter_outgoing_non_wp_user_author' ], 10, 2 );
		add_action( 'newspack_network_incoming_cap_guest_authors', [ __CLASS__, 'on_guest_authors_incoming' ], 10, 3 );


		if ( ! is_admin() ) {
			// These filters are for presentation - not ingesting or distributing.
			add_filter( 'get_coauthors', [ __CLASS__, 'filter_get_coauthors' ], 10, 2 );
			add_filter( 'newspack_author_bio_name', [ __CLASS__, 'filter_newspack_author_bio_name' ], 10, 3 );
			add_filter( 'author_link', [ __CLASS__, 'filter_author_link' ], 20, 3 );
		}
	}

	/**
	 * Filters outgoing author to include CAP's guest author if applicable.
	 *
	 * @param mixed $retval The return value.
	 * @param array $author The author.
	 *
	 * @return array The guest author or empty array.
	 */
	public static function filter_outgoing_non_wp_user_author( $retval, $author ): array {

		if ( ! is_object( $author ) || ! isset( $author->type ) || 'guest-author' !== $author->type ) {
			Debugger::log( 'Failed adding guest author to outgoing post' );

			return [];
		}

		global $coauthors_plus;

		$author = (array) $author;
		$author['type'] = 'guest_author';

		// Gets the guest author avatar.
		// We only want to send an actual uploaded avatar, we don't want to send the fallback avatar, like gravatar.
		// If no avatar was set, let it default to the fallback set in the target site.
		$author_avatar = $coauthors_plus->guest_authors->get_guest_author_thumbnail( $author, 80 );
		if ( $author_avatar ) {
			$author['avatar_img_tag'] = $author_avatar;
		}

		return $author;
	}

	/**
	 * Filters the coauthors of a post to include CAP's guest authors
	 *
	 * @param array $coauthors Array of coauthors.
	 * @param int   $post_id Post ID.
	 *
	 * @return array
	 */
	public static function filter_get_coauthors( $coauthors, $post_id ) {
		if ( empty( get_post_meta( $post_id, Incoming_Post::NETWORK_POST_ID_META, true ) ) ) {
			return $coauthors;
		}

		$distributed_authors   = get_post_meta( $post_id, self::GUEST_AUTHORS_META_KEY, true );
		$assigned_author_names = get_post_meta( $post_id, self::ASSIGNED_AUTHORS_META_KEY, true );

		if ( ! $distributed_authors ) {
			return $coauthors;
		}

		$assigned_authors = [];
		foreach ( $coauthors as $coauthor ) {
			if ( empty( $assigned_author_names ) || in_array( $coauthor->display_name, $assigned_author_names ) ) {
				$assigned_authors[] = $coauthor;
			}
		}

		foreach ( $distributed_authors as $distributed_author ) {
			if ( 'guest_author' !== $distributed_author['type'] ) {
				continue;
			}

			if ( empty( $assigned_author_names ) || in_array( $distributed_author['display_name'], $assigned_author_names ) ) {
				// This removes the author URL from the guest author.
				$distributed_author['user_nicename'] = '';
				$distributed_author['ID']            = - 2;
				$assigned_authors[] = (object) $distributed_author;
			}
		}

		return $assigned_authors;
	}

	/**
	 * Add job title for guest authors in the author bio.
	 *
	 * @param string $author_name The author name.
	 * @param int    $author_id The author ID.
	 * @param object $author The author object.
	 */
	public static function filter_newspack_author_bio_name( $author_name, $author_id, $author = null ) {
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
	 *
	 * @return string
	 */
	public static function filter_author_link( $link, $author_id, $author_nicename ) {
		if ( - 2 === $author_id && empty( $author_nicename ) ) {
			$link = '#';
		}

		return $link;
	}

	/**
	 * Action callback for reacting to incoming guest authors.
	 *
	 * @param int   $post_id The post ID.
	 * @param array $guest_authors The guest authors.
	 * @param array $all_authors All synced authors.
	 *
	 * @return void
	 */
	public static function on_guest_authors_incoming( $post_id, $guest_authors, $all_authors ): void {
		if ( empty( $guest_authors ) ) {
			delete_post_meta( $post_id, self::GUEST_AUTHORS_META_KEY );
			delete_post_meta( $post_id, self::ASSIGNED_AUTHORS_META_KEY );
			return;
		}

		update_post_meta( $post_id, self::ASSIGNED_AUTHORS_META_KEY, wp_list_pluck( $all_authors, 'display_name' ) );
		update_post_meta( $post_id, self::GUEST_AUTHORS_META_KEY, $guest_authors );
	}

	/**
	 * Filter callback.
	 *
	 * Allow the guest authors meta key to be ignored when distributing post meta.
	 *
	 * @param array $ignored_keys The ignored keys to filter.
	 *
	 * @return array $ignored_keys with one more added.
	 */
	public static function filter_ignored_post_meta_keys( array $ignored_keys ): array {
		$ignored_keys[] = self::GUEST_AUTHORS_META_KEY;
		$ignored_keys[] = self::ASSIGNED_AUTHORS_META_KEY;

		return $ignored_keys;
	}
}
