<?php
/**
 * Newspack Network Co-Authors Plus authors.
 *
 * @package Newspack
 */

namespace Newspack_Network\Content_Distribution;

use Newspack_Network\Content_Distribution as Content_Distribution_Class;
use Newspack_Network\Debugger;
use Newspack_Network\User_Update_Watcher;
use WP_Post;

/**
 * This class handles the Guest Contributors Newspack offers for CAP.
 *
 * For Co-Author Plus Guest Authors, see Cap_Guest_Authors.
 */
class Cap_Authors {

	/**
	 * The key for the cap authors in the payload post data.
	 *
	 * @var string
	 */
	const PAYLOAD_POST_DATA_AUTHORS_KEY = 'cap_authors';

	/**
	 * Get things going.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( ! self::is_co_authors_plus_active() ) {
			return;
		}

		add_action( 'set_object_terms', [ __CLASS__, 'on_cap_authors_change' ], 20, 6 );
		add_action( 'newspack_network_incoming_post_after_save', [ __CLASS__, 'ingest_incoming_for_post' ], 10, 3 );

		add_filter( 'newspack_network_outgoing_payload_post_data', [ __CLASS__, 'add_co_authors_to_payload_post_data' ], 10, 2 );

		/**
		 * Enables Co-Authors Plus Guest Authors support in content distribution.
		 * When enabled, guest author data is synced across the network.
		 *
		 * @constant NEWSPACK_ENABLE_CAP_GUEST_AUTHORS
		 * @type     bool
		 * @default  Guest authors support disabled
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_ENABLE_CAP_GUEST_AUTHORS', true );
		 */
		if ( defined( 'NEWSPACK_ENABLE_CAP_GUEST_AUTHORS' ) && NEWSPACK_ENABLE_CAP_GUEST_AUTHORS ) {
			Cap_Guest_Authors::init();
		}
	}

	/**
	 * Helper to check if Co-Authors Plus is active.
	 *
	 * @return bool Whether Co-Authors Plus is active.
	 */
	public static function is_co_authors_plus_active(): bool {
		global $coauthors_plus;

		return $coauthors_plus instanceof \CoAuthors_Plus && function_exists( 'get_coauthors' );
	}

	/**
	 * Action callback.
	 *
	 * Add CAP authors to the distribution queue when they change.
	 *
	 * @param int    $object_id  The object ID.
	 * @param array  $terms      The terms.
	 * @param array  $tt_ids     The term taxonomy IDs.
	 * @param string $taxonomy   The taxonomy.
	 * @param bool   $append     Whether to append.
	 * @param array  $old_tt_ids The old term taxonomy IDs.
	 *
	 * @return void
	 */
	public static function on_cap_authors_change( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		if ( 'author' !== $taxonomy ) { // Co-Authors Plus author taxonomy.
			return;
		}
		// If the terms are the same, we don't need to do anything. Note that one array has string values and one has
		// int values, so we use array_map with the intval for the comparison.
		if ( array_map( 'intval', $old_tt_ids ) === array_map( 'intval', $tt_ids ) ) {
			return;
		}

		if ( ! Content_Distribution_Class::is_post_distributed( $object_id ) ) {
			return;
		}

		Content_Distribution_Class::queue_post_distribution( $object_id, self::PAYLOAD_POST_DATA_AUTHORS_KEY );
	}

	/**
	 * Filter callback: Add the Co-Authors Plus authors to the payload post data.
	 *
	 * @param array   $payload_post_data The post data for the payload.
	 * @param WP_Post $post              Post to get authors for.
	 *
	 * @return array The post data for the payload.
	 */
	public static function add_co_authors_to_payload_post_data( $payload_post_data, $post ) {
		if ( ! self::is_co_authors_plus_active() ) {
			return $payload_post_data;
		}

		$co_authors = get_coauthors( $post->ID );
		if ( empty( $co_authors ) ) {
			return $payload_post_data;
		}

		$payload_post_data[ self::PAYLOAD_POST_DATA_AUTHORS_KEY ] = [];

		foreach ( $co_authors as $co_author ) {
			if ( is_a( $co_author, 'WP_User' ) ) {
				$payload_post_data[ self::PAYLOAD_POST_DATA_AUTHORS_KEY ][] = Outgoing_Post::get_outgoing_wp_user_author( $co_author );
				continue;
			}

			$other_kind_of_author = apply_filters( 'newspack_network_outgoing_non_wp_user_author', false, $co_author );
			if ( ! empty( $other_kind_of_author ) ) {
				$payload_post_data[ self::PAYLOAD_POST_DATA_AUTHORS_KEY ][] = $other_kind_of_author;
			}
		}

		return $payload_post_data;
	}

	/**
	 * Action callback: Ingest authors for a post distributed to this site
	 *
	 * @param array   $post_data  The post_data part of the payload.
	 * @param WP_post $post       The post.
	 * @param string  $remote_url The remote URL.
	 *
	 * @return void
	 */
	public static function ingest_incoming_for_post( $post_data, $post, $remote_url ): void {
		if ( ! self::is_co_authors_plus_active() ) {
			return;
		}

		$cap_authors_from_payload = $post_data[ self::PAYLOAD_POST_DATA_AUTHORS_KEY ] ?? [];

		Debugger::log( 'Ingesting authors from networked post.' );
		User_Update_Watcher::$enabled = false;

		$guest_contributors = [];
		$guest_authors      = [];

		foreach ( $cap_authors_from_payload as $author ) {
			$author_type = $author['type'] ?? '';
			switch ( $author_type ) {
				case 'wp_user':
					$user = Incoming_Post::get_incoming_wp_user_author( $remote_url, $author );
					if ( is_wp_error( $user ) ) {
						Debugger::log( 'Error ingesting guest contributor: ' . $user->get_error_message() );
					}
					$guest_contributors[] = $user->user_nicename;
					break;
				case 'guest_author':
					$guest_authors[] = $author;
					break;
			}
		}

		do_action( 'newspack_network_incoming_cap_guest_authors', $post->ID, $guest_authors, $cap_authors_from_payload );

		global $coauthors_plus;
		$coauthors_plus->add_coauthors( $post->ID, $guest_contributors );
	}
}
