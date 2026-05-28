<?php
/**
 * Newspack Network author distribution.
 *
 * @package Newspack
 */

namespace Newspack_Network\Distributor_Customizations;

use Newspack\Data_Events;
use Newspack_Network\Debugger;
use Newspack_Network\User_Update_Watcher;
use WP_Error;

/**
 * Class to handle author distribution.
 *
 * Every time a post is distributed, we also send all the information about the author (or authors if CAP is enabled)
 * On the target site, the plugin will create the authors if they don't exist, and override the byline
 */
class Author_Distribution {

	/**
	 * Initializes the class
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'dt_push_post_args', [ __CLASS__, 'add_author_data_to_push' ], 10, 2 );
		add_filter( 'dt_subscription_post_args', [ __CLASS__, 'add_author_data_to_push' ], 10, 2 );
		add_filter( 'dt_post_to_pull', [ __CLASS__, 'add_author_data_to_pull' ] );
		add_filter( 'dt_syncable_taxonomies', [ __CLASS__, 'filter_syncable_taxonomies' ] );

		add_filter( 'rest_request_after_callbacks', [ __CLASS__, 'after_coauthors_update' ], 10, 3 );
	}

	/**
	 * Removes CoAuthors Plus' author taxonomy from the list of taxonomies to be synced.
	 *
	 * This is crucial for out integration with CAP, as we are handling author syncing ourselves and we don't want
	 * Distributor to try to sync the author taxonomy.
	 *
	 * @param array $taxonomies An array of taxonomies slugs.
	 * @return array
	 */
	public static function filter_syncable_taxonomies( $taxonomies ) {
		return array_filter(
			$taxonomies,
			function( $taxonomy ) {
				return 'author' !== $taxonomy;
			}
		);
	}

	/**
	 * Filters the post data sent on a push to add the author data.
	 *
	 * This callback is also used to add data to the post sent to the subscription endpoint.
	 * (sends an update to the linked posts in other sites)
	 *
	 * @param array   $post_body The post data.
	 * @param WP_Post $post The post object.
	 * @return array
	 */
	public static function add_author_data_to_push( $post_body, $post ) {
		$authors = self::get_authors_for_distribution( $post );
		if ( ! empty( $authors ) ) {
			$post_body['newspack_network_authors'] = $authors;
		}
		return $post_body;
	}

	/**
	 * Filters the post data for a REST API response.
	 *
	 * This acts on requests made to pull a post from this site.
	 *
	 * @param array $post_array The post data.
	 */
	public static function add_author_data_to_pull( $post_array ) {

		$authors = self::get_authors_for_distribution( (object) $post_array );

		if ( ! empty( $authors ) ) {
			Debugger::log( 'Adding authors to pull' );
			$post_array['newspack_network_authors'] = $authors;
		}

		return $post_array;
	}

	/**
	 * Get the authors of a post to be added to the distribution payload.
	 *
	 * @param WP_Post $post The post object.
	 * @return array An array of authors.
	 */
	private static function get_authors_for_distribution( $post ) {
		$author = self::get_wp_user_for_distribution( $post->post_author );

		if ( ! function_exists( 'get_coauthors' ) ) {
			if ( is_wp_error( $author ) ) {
				Debugger::log( 'Error getting author ' . $post->post_author . ' for distribution on post ' . $post->ID . ': ' . $author->get_error_message() );
				return [];
			}
			return [ $author ];
		}

		$co_authors = get_coauthors( $post->ID );
		if ( empty( $co_authors ) ) {
			if ( is_wp_error( $author ) ) {
				Debugger::log( 'Error getting author ' . $post->post_author . ' for distribution on post ' . $post->ID . ': ' . $author->get_error_message() );
				return [];
			}
			return [ $author ];
		}

		$authors = [];

		foreach ( $co_authors as $co_author ) {
			if ( is_a( $co_author, 'WP_User' ) ) {
				// This will never return an error because we are checking for is_a() first.
				$authors[] = self::get_wp_user_for_distribution( $co_author );
				continue;
			}

			$guest_author = self::get_guest_author_for_distribution( $co_author );
			if ( is_wp_error( $guest_author ) ) {
				Debugger::log( 'Error getting guest author for distribution on post ' . $post->ID . ': ' . $guest_author->get_error_message() );
				Debugger::log( $co_author );
				continue;
			}
			$authors[] = $guest_author;
		}

		return $authors;
	}

	/**
	 * Gets the user data of a WP user to be distributed along with the post.
	 *
	 * @param int|WP_Post $user The user ID or object.
	 * @return WP_Error|array
	 */
	private static function get_wp_user_for_distribution( $user ) {
		if ( ! is_a( $user, 'WP_User' ) ) {
			$user = get_user_by( 'ID', $user );
		}

		if ( ! $user ) {
			return new WP_Error( 'Error getting WP User details for distribution. Invalid User' );
		}

		$author = [
			'type' => 'wp_user',
			'ID'   => $user->ID,
		];


		foreach ( User_Update_Watcher::$user_props as $prop ) {
			if ( isset( $user->$prop ) ) {
				$author[ $prop ] = $user->$prop;
			}
		}

		// CoAuthors' guest authors have a 'website' property.
		if ( isset( $user->website ) ) {
			$author['website'] = $user->website;
		}

		foreach ( User_Update_Watcher::$watched_meta as $meta_key ) {
			$author[ $meta_key ] = get_user_meta( $user->ID, $meta_key, true );
		}

		return $author;
	}

	/**
	 * Get the guest author data to be distributed along with the post.
	 *
	 * @param object $guest_author The Guest Author object.
	 * @return WP_Error|array
	 */
	private static function get_guest_author_for_distribution( $guest_author ) {

		// CoAuthors plugin existence was checked in get_authors_for_distribution().
		global $coauthors_plus;

		if ( ! is_object( $guest_author ) || ! isset( $guest_author->type ) || 'guest-author' !== $guest_author->type ) {
			return new WP_Error( 'Error getting guest author details for distribution. Invalid Guest Author' );
		}

		$author         = (array) $guest_author;
		$author['type'] = 'guest_author';

		// Gets the guest author avatar.
		// We only want to send an actual uploaded avatar, we don't want to send the fallback avatar, like gravatar.
		// If no avatar was set, let it default to the fallback set in the target site.
		$author_avatar = $coauthors_plus->guest_authors->get_guest_author_thumbnail( $guest_author, 80 );
		if ( $author_avatar ) {
			$author['avatar_img_tag'] = $author_avatar;
		}

		return $author;
	}

	/**
	 * Sends an extra notification to subscribers when the authors of a post are updated.
	 *
	 * CoAuthors Plus updates the authors through an additional ajax request in the editor after the post is updated,
	 * therefore when we change the authors and update the post in the Editor, the notification sent to the subscribers still
	 * has the old authors
	 *
	 * We don't filter the response here, we just send the notification.
	 *
	 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response Result to send to the client.
	 * @param array                                            $handler  Route handler used for the request.
	 * @param WP_REST_Request                                  $request  Request used to generate the response.
	 * @return WP_REST_Response|WP_HTTP_Response|WP_Error|mixed
	 */
	public static function after_coauthors_update( $response, $handler, $request ) {

		if ( ! class_exists( 'CoAuthors\API\Endpoints' ) || ! function_exists( 'Distributor\Subscriptions\send_notifications' ) ) {
			return $response;
		}

		$coauthors_endpoint_base = \CoAuthors\API\Endpoints::NS . '/' . \CoAuthors\API\Endpoints::AUTHORS_ROUTE;

		if ( false === strpos( $request->get_route(), $coauthors_endpoint_base ) ) {
			return $response;
		}

		if ( 'POST' !== $request->get_method() ) {
			return $response;
		}

		\Distributor\Subscriptions\send_notifications( (int) $request->get_param( 'post_id' ) );

		return $response;
	}
}
