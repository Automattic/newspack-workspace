<?php
/**
 * Newspack Network Users methods.
 *
 * @package Newspack
 */

namespace Newspack_Network\Utils;

use Newspack_Network\Debugger;

/**
 * Class to watch the user for updates and trigger events
 */
class Users {
	const USER_META_REMOTE_SITE = 'newspack_remote_site';
	const USER_META_REMOTE_ID = 'newspack_remote_id';

	/**
	 * Gets an existing user or creates a user propagated from another site in the Network
	 *
	 * @param string $email The email of the user to look for. If no user is found, a new one will be created with this email.
	 * @param string $remote_site_url The URL of the remote site. Used only when a new user is created.
	 * @param string $remote_id The ID of the user in the remote site. Used only when a new user is created.
	 * @param array  $insert_array An array of additional fields to be passed to wp_insert_user() when creating a new user. Use this to set the user's role, the default is NEWSPACK_NETWORK_READER_ROLE.
	 * @return \WP_User|\WP_Error
	 */
	public static function get_or_create_user_by_email( $email, $remote_site_url, $remote_id, $insert_array = [] ) {
		$existing_user = get_user_by( 'email', $email );

		if ( $existing_user ) {
			/**
			 * Fires when fetching an existing network reader account.
			 *
			 * @param WP_User $new_user The existing user.
			 */
			do_action( 'newspack_network_network_reader', $existing_user );

			return $existing_user;
		}

		$nicename = self::generate_user_nicename( $email );

		$user_array = [
			'user_login'    => substr( $email, 0, 60 ),
			'user_email'    => $email,
			'user_nicename' => $nicename,
			'display_name'  => $nicename,
			'user_pass'     => wp_generate_password(),
			'role'          => NEWSPACK_NETWORK_READER_ROLE,
		];

		$user_array = array_merge( $user_array, $insert_array );

		if ( isset( $user_array['meta_input'] ) ) {
			$user_array['meta_input'] = (array) $user_array['meta_input'];
		}

		$user_id = wp_insert_user( $user_array );

		if ( is_wp_error( $user_id ) ) {
			Debugger::log( 'Error creating user: ' . $user_id->get_error_message() );
			return $user_id;
		}

		update_user_meta( $user_id, self::USER_META_REMOTE_SITE, $remote_site_url );
		update_user_meta( $user_id, self::USER_META_REMOTE_ID, $remote_id );

		$new_user = get_user_by( 'id', $user_id );

		/**
		 * Fires when a new network reader account is created and all network user meta has been added.
		 *
		 * @param WP_User $new_user The newly created user.
		 */
		do_action( 'newspack_network_new_network_reader', $new_user );

		return $new_user;
	}

	/**
	 * Generate a URL-sanitized version of the given string for a new reader account.
	 *
	 * @param string $name User's display name, or email if not available.
	 * @return string
	 */
	public static function generate_user_nicename( $name ) {
		$name = self::strip_email_domain( $name ); // If an email address, strip the domain.
		return substr( \sanitize_title( \sanitize_user( $name, true ) ), 0, 50 );
	}

	/**
	 * Strip the domain part of an email address string.
	 * If not an email address, just return the string.
	 *
	 * @param string $str String to check.
	 * @return string
	 */
	public static function strip_email_domain( $str ) {
		return trim( explode( '@', $str, 2 )[0] );
	}

	/**
	 * Looks for avatar information and tries to sideload it to the user
	 *
	 * @param int   $user_id The user ID to add the avatar to.
	 * @param array $user_data The user data where we are going to look for the avatar. We are looking for the 'simple_local_avatar' meta key.
	 * @param bool  $overwrite Whether to overwrite the existing avatar or not.
	 * @return bool True if the avatar was sideloaded, false otherwise.
	 */
	public static function maybe_sideload_avatar( $user_id, $user_data, $overwrite ) {

		Debugger::log( 'Attempting to sideload user avatar' );

		global $simple_local_avatars;
		if ( ! $simple_local_avatars || ! is_a( $simple_local_avatars, 'Simple_Local_Avatars' ) ) {
			Debugger::log( 'Simple Local Avatars plugin not active, skipping' );
			return false;
		}

		$avatar_meta_key = 'simple_local_avatar';

		$existing_avatar = get_user_meta( $user_id, $avatar_meta_key, true );

		if ( ! empty( $existing_avatar ) && ! $overwrite ) {
			Debugger::log( 'User already has an avatar and overwrite is false, skipping' );
			return false;
		}

		if ( ! is_array( $user_data ) ) {
			$user_data = (array) $user_data;
		}

		if ( array_key_exists( $avatar_meta_key, $user_data ) && is_array( $user_data[ $avatar_meta_key ] ) && ! empty( $user_data[ $avatar_meta_key ]['full'] ) ) {
			$avatar_url = $user_data[ $avatar_meta_key ]['full'];

			// The avatar URL comes from the network event payload and is fetched server-side
			// (media_sideload_image -> download_url -> wp_remote_get, which does not block
			// private/reserved hosts), so a peer could otherwise make this site request an
			// internal address (SSRF). Reject anything that isn't a valid external URL.
			if ( ! is_string( $avatar_url ) || ! wp_http_validate_url( $avatar_url ) ) {
				Debugger::log( 'Avatar URL is not a valid external URL, skipping sideload' );
				return false;
			}

			Debugger::log( 'Updating user avatar' );

			if ( ! function_exists( 'media_sideload_image' ) ) {
				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}

			$avatar_id = media_sideload_image( $avatar_url, 0, null, 'id' );

			if ( is_wp_error( $avatar_id ) ) {
				Debugger::log( 'Error sideloading avatar: ' . $avatar_id->get_error_message() );
				return false;
			}

			if ( $avatar_id && is_int( $avatar_id ) ) {
				Debugger::log( 'Avatar successfully sideloaded with ID: ' . $avatar_id );
				$simple_local_avatars->assign_new_user_avatar( $avatar_id, $user_id );
				return true;
			}
		}

		Debugger::log( 'No avatar found in user data' );
		return false;
	}

	/**
	 * Get synchronization-entailing user roles.
	 */
	public static function get_synced_user_roles() {
		if ( ! method_exists( '\Newspack\Reader_Activation', 'get_reader_roles' ) ) {
			return [];
		}
		return \Newspack\Reader_Activation::get_reader_roles();
	}

	/**
	 * Get synchronized users count.
	 */
	public static function get_synchronized_users_count() {
		return count( self::get_synchronized_users( [ 'id' ] ) );
	}

	/**
	 * Get synchronized users emails.
	 */
	public static function get_synchronized_users_emails() {
		return array_map( 'strtolower', array_column( self::get_synchronized_users( [ 'user_email' ] ), 'user_email' ) );
	}

	/**
	 * Get no-role users emails.
	 */
	public static function get_no_role_users_emails() {
		global $wpdb;
		$no_role_users_emails = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT user_email FROM wp_users WHERE ID IN (SELECT user_id FROM wp_usermeta WHERE meta_key = 'wp_capabilities' AND (meta_value = 'a:0:{}' OR meta_value = '')) OR ID NOT IN (SELECT user_id FROM wp_usermeta WHERE meta_key = 'wp_capabilities')"
		);
		return array_map( 'strtolower', array_column( $no_role_users_emails, 'user_email' ) );
	}

	/**
	 * Get synchronized users.
	 *
	 * @param array $fields Fields to return.
	 */
	public static function get_synchronized_users( $fields = [] ) {
		return get_users(
			[
				'role__in' => self::get_synced_user_roles(),
				'fields'   => $fields,
				'number'   => -1,
			]
		);
	}

	/**
	 * Get not synchronized users count.
	 *
	 * @param array $fields Fields to return.
	 */
	public static function get_not_synchronized_users( $fields = [] ) {
		return get_users(
			[
				'role__not_in' => self::get_synced_user_roles(),
				'fields'       => $fields,
				'number'       => -1,
			]
		);
	}

	/**
	 * Get synchronized users emails.
	 */
	public static function get_not_synchronized_users_emails() {
		return array_map( 'strtolower', array_column( self::get_not_synchronized_users( [ 'user_email' ] ), 'user_email' ) );
	}

	/**
	 * Get not synchronized users count.
	 */
	public static function get_not_synchronized_users_count() {
		return count( self::get_not_synchronized_users( [ 'id' ] ) );
	}

	/**
	 * Get user's active subscriptions tied to a network ID.
	 *
	 * @param string $email The email of the user to look for.
	 * @param array  $plan_network_ids Network IDs of the plans.
	 * @return array Array of active subscription IDs.
	 */
	public static function get_users_active_subscriptions_tied_to_network_ids( $email, $plan_network_ids ) {
		if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
			return [];
		}
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return [];
		}
		// If a membership is a "shadowed" membership, no subscription will be tied to it.
		// The relevant subscription has to be found by matching the plan-granting products with subscriptions.
		$plan_ids = get_posts(
			[
				'meta_key'     => \Newspack_Network\Woocommerce_Memberships\Admin::NETWORK_ID_META_KEY,
				'meta_value'   => $plan_network_ids, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_compare' => 'IN',
				'post_type'    => \Newspack_Network\Woocommerce_Memberships\Admin::MEMBERSHIP_PLANS_CPT,
				'field'        => 'ID',
			]
		);
		$product_ids = [];
		foreach ( $plan_ids as $plan_id ) {
			// Get the products that grant membership in the plan.
			$product_ids = array_merge( $product_ids, get_post_meta( $plan_id->ID, '_product_ids', true ) );
		}
		// Get any active subscriptions for these product IDs.
		$active_subscription_ids = [];
		foreach ( $product_ids as $product_id ) {
			$args = [
				'customer_id'            => $user->ID,
				'product_id'             => $product_id,
				'subscription_status'    => 'active',
				'subscriptions_per_page' => 1,
			];
			$subscriptions = \wcs_get_subscriptions( $args );
			$subscription = reset( $subscriptions );
			if ( $subscription ) {
				$active_subscription_ids[] = $subscription->get_id();
			}
		}
		return $active_subscription_ids;
	}
}
