<?php
/**
 * Newspack Network author ingestion.
 *
 * @package Newspack
 */

namespace Newspack_Network\Distributor_Customizations;

use Newspack\Data_Events;
use Newspack_Network\Debugger;
use Newspack_Network\User_Update_Watcher;
use Newspack_Network\Utils\Users as User_Utils;

/**
 * Class to handle author ingestion.
 *
 * This class is used to handle the authorship data added by Author_Distribution to the distributed post
 */
class Author_Ingestion {

	/**
	 * Array of authors from pulled posts. See self::capture_authorship.
	 *
	 * @var array
	 */
	private static $pulled_posts = [];

	/**
	 * Initializes the class
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_insert_post', [ __CLASS__, 'handle_rest_insertion' ], 10, 2 );
		add_action( 'dt_process_subscription_attributes', [ __CLASS__, 'handle_rest_insertion' ], 10, 2 );
		add_action( 'dt_pull_post', [ __CLASS__, 'handle_pull' ], 10, 3 );
	}

	/**
	 * Gets the CoAuthors Plus main object, if present
	 *
	 * @return false|\CoAuthors_Plus
	 */
	public static function get_coauthors_plus() {
		global $coauthors_plus;
		if ( is_null( $coauthors_plus ) || ( ! $coauthors_plus instanceof \CoAuthors_Plus ) ) {
			return false;
		}
		return $coauthors_plus;
	}

	/**
	 * Fired when a post is inserted via the REST API
	 *
	 * Checks if there are newspack network author information and ingests it.
	 *
	 * This callback is also used when a subscription update is received.
	 *
	 * @param WP_Post         $post     Inserted post object.
	 * @param WP_REST_Request $request  Request object.
	 * @return void
	 */
	public static function handle_rest_insertion( $post, $request ) {

		$distributed_authors = $request->get_param( 'newspack_network_authors' );
		if ( empty( $distributed_authors ) ) {
			return;
		}

		self::ingest_authors_for_post( $post->ID, $distributed_authors );
	}

	/**
	 * Ingest authors for a post distributed to this site
	 *
	 * @param int   $post_id The post ID.
	 * @param array $distributed_authors The distributed authors array.
	 * @return void
	 */
	public static function ingest_authors_for_post( $post_id, $distributed_authors ) {

		Debugger::log( 'Ingesting authors from distributed post.' );

		User_Update_Watcher::$enabled = false;

		update_post_meta( $post_id, 'newspack_network_authors', $distributed_authors );

		$coauthors_plus = self::get_coauthors_plus();
		$coauthors      = [];

		foreach ( $distributed_authors as $author ) {
			// We only ingest WP Users. Guest authors are only stored in the newspack_network_authors post meta.
			if ( empty( $author['type'] ) || 'wp_user' != $author['type'] ) {
				continue;
			}

			Debugger::log( 'Ingesting author: ' . $author['user_email'] );

			$insert_array = [
				'role' => 'author',
			];

			foreach ( User_Update_Watcher::$user_props as $prop ) {
				if ( isset( $author[ $prop ] ) ) {
					$insert_array[ $prop ] = $author[ $prop ];
				}
			}

			$user = User_Utils::get_or_create_user_by_email( $author['user_email'], get_post_meta( $post_id, 'dt_original_site_url', true ), $author['ID'], $insert_array );

			if ( is_wp_error( $user ) ) {
				Debugger::log( 'Error creating user: ' . $user->get_error_message() );
				continue;
			}

			foreach ( User_Update_Watcher::get_writable_meta() as $meta_key ) {
				if ( isset( $author[ $meta_key ] ) ) {
					update_user_meta( $user->ID, $meta_key, $author[ $meta_key ] );
				}
			}

			User_Utils::maybe_sideload_avatar( $user->ID, $author, false );

			// If CoAuthors Plus is not present, just assign the first author as the post author.
			if ( ! $coauthors_plus ) {
				Debugger::log( 'CoAuthors Plus not present, assigning first author as post author.' );
				wp_update_post(
					[
						'ID'          => $post_id,
						'post_author' => $user->ID,
					]
				);
				break;
			}

			$coauthors[] = $user->user_nicename;
		}

		if ( $coauthors_plus ) {
			Debugger::log( 'CoAuthors Plus present, assigning coauthors:' );
			Debugger::log( $coauthors );
			$coauthors_plus->add_coauthors( $post_id, $coauthors );
		}
	}

	/**
	 * Triggered when a post is pulled from a remote site.
	 *
	 * @param int                                                 $new_post_id   The new post ID that was pulled.
	 * @param \Distributor\ExternalConnections\ExternalConnection $connection    The Distributor connection pulling the post.
	 * @param array                                               $post_array    The original post data retrieved via the connection.
	 * @return void
	 */
	public static function handle_pull( $new_post_id, $connection, $post_array ) {

		if ( empty( $post_array['newspack_network_authors'] ) ) {
			return;
		}

		$distributed_authors = $post_array['newspack_network_authors'];

		self::ingest_authors_for_post( $new_post_id, $distributed_authors );
	}
}
