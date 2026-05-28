<?php
/**
 * Newspack Network watcher for updates on users' information
 *
 * @package Newspack
 */

namespace Newspack_Network;

/**
 * Class to watch the user for updates and trigger events
 */
class User_Update_Watcher {

	/**
	 * Flag to indicate if the watcher should watch.
	 * If false, this class won't fire any events to avoid an infinite loop in author updates.
	 *
	 * @var boolean
	 */
	public static $enabled = true;

	/**
	 * Holds information about the users that were updated in this request, if any.
	 *
	 * @var array
	 */
	public static $updated_users = [];

	/**
	 * The metadata we're watching and syncing.
	 *
	 * @var array
	 */
	public static $watched_meta = [
		// Social Links.
		'facebook',
		'instagram',
		'linkedin',
		'myspace',
		'pinterest',
		'soundcloud',
		'tumblr',
		'twitter',
		'youtube',
		'wikipedia',

		// Core bio.
		'first_name',
		'last_name',
		'description',

		// Shipping and Billing.
		'billing_first_name',
		'billing_last_name',
		'billing_address_1',
		'billing_address_2',
		'billing_city',
		'billing_state',
		'billing_postcode',
		'billing_country',
		'shipping_first_name',
		'shipping_last_name',
		'shipping_address_1',
		'shipping_address_2',
		'shipping_city',
		'shipping_state',
		'shipping_postcode',
		'shipping_country',

		// Newspack.
		'newspack_job_title',
		'newspack_role',
		'newspack_employer',
		'newspack_phone_number',

		// Yoast SEO.
		'wpseo_title',
		'wpseo_metadesc',
		'wpseo_noindex_author',
		'wpseo_content_analysis_disable',
		'wpseo_keyword_analysis_disable',
		'wpseo_inclusive_language_analysis_disable',

		// Simple Local Avatars.
		'simple_local_avatar',
		'simple_local_avatar_rating',
	];

	/**
	 * Meta keys we watched but we don't want to update in the same way we do with all the others.
	 *
	 * @var array
	 */
	public static $read_only_meta = [
		'simple_local_avatar', // The avatar is sideloaded in a different way.
	];

	/**
	 * The user properties we're watching and syncing.
	 *
	 * @var array
	 */
	public static $user_props = [
		'display_name',
		'user_email',
		'user_url',
	];

	/**
	 * Runs the initialization.
	 */
	public static function init() {
		add_action( 'add_user_meta', [ __CLASS__, 'add_user_meta' ], 10, 3 );
		add_action( 'update_user_meta', [ __CLASS__, 'update_user_meta' ], 10, 4 );
		add_action( 'deleted_user_meta', [ __CLASS__, 'deleted_user_meta' ], 10, 3 );
		add_action( 'profile_update', [ __CLASS__, 'profile_update' ], 10, 3 );
		add_action( 'shutdown', [ __CLASS__, 'maybe_trigger_event' ] );
	}

	/**
	 * Gets a list of metadata we can update.
	 *
	 * @return array
	 */
	public static function get_writable_meta() {
		return array_diff( self::$watched_meta, self::$read_only_meta );
	}

	/**
	 * Adds a change to the list of changes in the current request
	 *
	 * @param int    $user_id The updated user ID.
	 * @param string $type The change type: meta or prop.
	 * @param string $key The key of the changed meta or prop.
	 * @param string $value The new value of the changed meta or prop.
	 * @param string $old_email In case of an email change, use the old email to find the user in the target site.
	 * @return void
	 */
	private static function add_change( $user_id, $type, $key, $value, $old_email = '' ) {
		if ( ! isset( self::$updated_users[ $user_id ] ) ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				self::$updated_users[ $user_id ] = [
					'email' => $user->user_email,
				];
			} else {
				return;
			}
		}

		// If the email is being updated, we need to use the old email to find the user in the target site.
		if ( ! empty( $old_email ) ) {
			self::$updated_users[ $user_id ]['email'] = $old_email;
		}

		if ( ! isset( self::$updated_users[ $user_id ][ $type ] ) ) {
			self::$updated_users[ $user_id ][ $type ] = [];
		}

		Debugger::log( 'Author update detected for ' . self::$updated_users[ $user_id ]['email'] . ": $type: $key" );

		self::$updated_users[ $user_id ][ $type ][ $key ] = $value;
	}

	/**
	 * Runs when a user meta is added
	 *
	 * @param int    $user_id    The user ID.
	 * @param string $meta_key   The meta key.
	 * @param mixed  $meta_value The meta value.
	 * @return void
	 */
	public static function add_user_meta( $user_id, $meta_key, $meta_value ) {
		if ( ! self::$enabled ) {
			return;
		}

		if ( in_array( $meta_key, self::$watched_meta, true ) ) {
			self::add_change( $user_id, 'meta', $meta_key, $meta_value );
		}
	}

	/**
	 * Runs when a user meta is updated
	 *
	 * @param int    $meta_id    The meta ID after successful update.
	 * @param int    $user_id    The user ID.
	 * @param string $meta_key   The meta key.
	 * @param mixed  $meta_value The meta value.
	 * @return void
	 */
	public static function update_user_meta( $meta_id, $user_id, $meta_key, $meta_value ) {
		if ( ! self::$enabled ) {
			return;
		}

		if ( in_array( $meta_key, self::$watched_meta, true ) ) {
			if ( method_exists( __CLASS__, 'watch_meta_' . $meta_key ) ) {
				call_user_func( [ __CLASS__, 'watch_meta_' . $meta_key ], $user_id, $meta_value );
				return;
			}
			self::add_change( $user_id, 'meta', $meta_key, $meta_value );
		}
	}

	/**
	 * Runs when a user meta is deleted
	 *
	 * @param string[] $meta_ids    An array of metadata entry IDs to delete.
	 * @param int      $user_id   ID of the object metadata is for.
	 * @param string   $meta_key    Metadata key.
	 * @return void
	 */
	public static function deleted_user_meta( $meta_ids, $user_id, $meta_key ) {
		if ( ! self::$enabled ) {
			return;
		}

		if ( in_array( $meta_key, self::$watched_meta, true ) ) {
			self::add_change( $user_id, 'meta', $meta_key, '' );
		}
	}

	/**
	 * Runs when a user is updated
	 *
	 * @param int     $user_id The user ID.
	 * @param WP_User $old_user_data The old user data.
	 * @param array   $user_data The new user data.
	 * @return void
	 */
	public static function profile_update( $user_id, $old_user_data, $user_data ) {
		if ( ! self::$enabled ) {
			return;
		}

		foreach ( self::$user_props as $prop ) {
			if ( $old_user_data->$prop !== $user_data[ $prop ] ) {
				$old_email = 'user_email' === $prop ? $old_user_data->$prop : '';
				self::add_change( $user_id, 'prop', $prop, $user_data[ $prop ], $old_email );
			}
		}
	}

	/**
	 * If there are any author updates, trigger an event.
	 *
	 * @return void
	 */
	public static function maybe_trigger_event() {
		if ( ! self::$enabled ) {
			return;
		}
		foreach ( self::$updated_users as $author ) {
			do_action( 'newspack_network_user_updated', $author );
		}
	}

	/**
	 * Watcher for the simple_local_avatar meta.
	 *
	 * This meta gets updated every time a new image size is created for the avatar,
	 * and we only want to fire an event if the media ID changes.
	 *
	 * @param int    $user_id The user ID.
	 * @param string $new_value The meta value.
	 * @return void
	 */
	public static function watch_meta_simple_local_avatar( $user_id, $new_value ) {
		$old_value = get_user_meta( $user_id, 'simple_local_avatar', true );

		Debugger::log( 'simple_local_avatar meta updated, but will only trigger a user update if the media id has changed.' );

		if ( ! is_array( $old_value ) || empty( $old_value['media_id'] ) ) {
			return;
		}

		if ( ! is_array( $new_value ) || empty( $new_value['media_id'] ) ) {
			return;
		}

		if ( (int) $old_value['media_id'] !== (int) $new_value['media_id'] ) {
			self::add_change( $user_id, 'meta', 'simple_local_avatar', $new_value );
		}
	}
}
