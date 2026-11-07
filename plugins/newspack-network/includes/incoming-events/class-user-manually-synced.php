<?php
/**
 * Newspack User Synced Incoming Event class
 *
 * @package Newspack
 */

namespace Newspack_Network\Incoming_Events;

use Newspack_Network\User_Manual_Sync;
use Newspack_Network\Debugger;
use Newspack_Network\User_Update_Watcher;
use Newspack_Network\Utils\Users as User_Utils;

/**
 * Class to handle the User Updated Event
 *
 * This event is always sent from the Hub and received by Nodes.
 */
class User_Manually_Synced extends Abstract_Incoming_Event {

	/**
	 * Processes the event
	 *
	 * @return void
	 */
	public function post_process_in_hub() {
		$this->maybe_sync_user();
	}

	/**
	 * Process event in Node
	 *
	 * @return void
	 */
	public function process_in_node() {
		$this->maybe_sync_user();
	}

	/**
	 * Maybe updates a new WP user based on this event
	 *
	 * Unlike the background `User_Updated` receiver, this handler intentionally accepts
	 * `$data->role` (including `administrator`) and `$data->user_login` without sanitization
	 * or allowlisting. Manual sync is the documented way an admin on one site propagates an
	 * admin role across the network, and the trust model assumes every Node in the network
	 * is operated by the same operator. If that model ever changes, both fields must be
	 * constrained here (sanitize `user_login` via `sanitize_user()`, restrict `role` to a
	 * safe set).
	 *
	 * A payload carrying only synced reader roles (or none) never demotes or renames an account
	 * holding any other role: its roles, display name and email stay unchanged (see
	 * below). A payload carrying any other role applies in full, demotions included.
	 *
	 * @return void
	 */
	public function maybe_sync_user() {
		$email = $this->get_email();
		Debugger::log( sprintf( 'Processing user_manually_synced for %s from %s.', $email, $this->get_site() ) );
		if ( ! $email ) {
			return;
		}

		User_Update_Watcher::$enabled = false;

		$user = User_Utils::get_or_create_user_by_email(
			$email,
			$this->get_site(),
			$this->data->user_id ?? '',
			[
				'user_login' => $this->data->user_login ?? $email,
			]
		);

		// If the user is not found by email, but can't be created due to user_login clash,
		// try again without setting the user_login (email will be used as user_login by default).
		if ( is_wp_error( $user ) && $user->get_error_code() === 'existing_user_login' ) {
			$user = User_Utils::get_or_create_user_by_email(
				$email,
				$this->get_site(),
				$this->data->user_id ?? ''
			);
		}

		if ( is_wp_error( $user ) ) {
			Debugger::log( 'Error creating user: ' . $user->get_error_message() );
			return;
		}

		// Get data passed for user.
		$data = $this->get_data();

		// Update user role if changed.
		$user_current_roles = $user->roles;
		$user_new_roles     = (array) ( $data->role ?? [] );
		$remove_roles       = array_diff( $user_current_roles, $user_new_roles );
		$add_roles          = array_diff( $user_new_roles, $user_current_roles );

		// A payload carrying only synced reader roles (or none) leaves an account that
		// holds any other role here with its roles, display name and email unchanged.
		// The account is matched by email alone, so such a payload is what arrives when
		// an admin syncs a reader whose address matches a staff account on this site,
		// and nothing in it tells that apart from a deliberate demotion. A demotion to
		// reader roles therefore has to be made on each site. A payload carrying any
		// other role, such as an administrator being propagated, applies in full.
		$reader_roles_only = empty( array_diff( $user_new_roles, User_Utils::get_synced_user_roles() ) );
		$keep_identity     = $reader_roles_only && ! User_Utils::is_syncable_account( $user );
		if ( $keep_identity ) {
			Debugger::log( 'Target holds a role outside the synced set; leaving its roles, display name and email unchanged.' );
		}

		// If the old and new role arrays aren't the same, update the roles.
		if ( ! $keep_identity && ( $remove_roles || $add_roles ) ) {
			// Get the user object.
			$current_user = new \WP_User( $user->ID );

			// Get rid of any roles that aren't being pushed.
			if ( $remove_roles ) {
				foreach ( $remove_roles as $role ) {
					$current_user->remove_role( $role );
				}
			}

			// Assign each new role.
			if ( $add_roles ) {
				foreach ( $add_roles as $role ) {
					$current_user->add_role( $role );
				}
			}
		}

		// Loop through user props and update. Roles are synced above via $data->role; the
		// props and meta below are restricted to the fixed set of fields the user sync
		// tracks, so an incoming payload can't write arbitrary props (user_pass, ...) or
		// meta (_application_passwords, wp_capabilities, ...).
		if ( isset( $data->prop ) ) {
			$incoming_props = (array) $data->prop;
			$update_array   = [
				'ID' => $user->ID,
			];
			foreach ( User_Update_Watcher::$user_props as $prop_key ) {
				if ( ! isset( $incoming_props[ $prop_key ] ) ) {
					continue;
				}
				if ( $keep_identity && in_array( $prop_key, [ 'display_name', 'user_email' ], true ) ) {
					continue;
				}
				$update_array[ $prop_key ] = $incoming_props[ $prop_key ];
			}
			// Only update if at least one allowed prop is present; $update_array always has 'ID'.
			if ( count( $update_array ) > 1 ) {
				Debugger::log( 'Manually syncing user with data: ' . print_r( $update_array, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
				wp_update_user( $update_array );
			}
		}

		// Loop through user meta and update.
		if ( isset( $data->meta ) ) {
			$incoming_meta = (array) $data->meta;
			foreach ( User_Update_Watcher::get_writable_meta() as $meta_key ) {
				if ( isset( $incoming_meta[ $meta_key ] ) ) {
					Debugger::log( 'Manually syncing user meta: ' . $meta_key );
					update_user_meta( $user->ID, $meta_key, $incoming_meta[ $meta_key ] );
				}
			}

			User_Utils::maybe_sideload_avatar( $user->ID, $data->meta, true );
		}
	}
}
