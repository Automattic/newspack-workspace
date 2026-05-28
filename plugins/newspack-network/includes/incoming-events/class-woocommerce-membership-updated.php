<?php
/**
 * Newspack Network Woocommerce Membership Updated event
 *
 * @package Newspack
 */

namespace Newspack_Network\Incoming_Events;

use Newspack_Network\Debugger;
use Newspack_Network\Utils\Users as User_Utils;
use Newspack_Network\User_Update_Watcher;
use Newspack_Network\Woocommerce_Memberships\Admin as Memberships_Admin;
use Newspack_Network\Woocommerce_Memberships\Events as Memberships_Events;
use WC_Memberships_User_Membership;

/**
 * Class to handle the Registered Incoming Event
 */
class Woocommerce_Membership_Updated extends Abstract_Incoming_Event {

	/**
	 * Processes the event
	 *
	 * @return void
	 */
	public function post_process_in_hub() {
		$this->update_membership();
	}

	/**
	 * Process event in Node
	 *
	 * @return void
	 */
	public function process_in_node() {
		$this->update_membership();
	}

	/**
	 * Maybe creates a new WP user based on this event
	 *
	 * @return void
	 */
	public function update_membership() {
		$email = $this->get_email();
		Debugger::log( 'Processing Woo Membership update with email: ' . $email );
		if ( ! $email ) {
			return;
		}

		if ( ! function_exists( 'wc_memberships_get_user_membership' ) || ! function_exists( 'wc_memberships_create_user_membership' ) ) {
			return;
		}

		global $wpdb;

		$local_plan_id = $wpdb->get_var( // phpcs:ignore
			$wpdb->prepare(
				"SELECT post_id from $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s AND post_id IN ( SELECT ID FROM $wpdb->posts WHERE post_type = %s ) ",
				Memberships_Admin::NETWORK_ID_META_KEY,
				$this->get_plan_network_id(),
				Memberships_Admin::MEMBERSHIP_PLANS_CPT
			)
		);

		if ( ! $local_plan_id ) {
			Debugger::log( 'Local plan not found' );
			return;
		}

		Memberships_Events::$pause_events = true;
		User_Update_Watcher::$enabled     = false;

		$user = User_Utils::get_or_create_user_by_email( $email, $this->get_site(), $this->data->user_id ?? '' );

		// Handle membership ownership transfer.
		$previous_email = $this->get_previous_email();
		if ( $previous_email ) {
			$this->transfer_membership( $user, $local_plan_id, $previous_email );
			return;
		}

		$user_membership = wc_memberships_get_user_membership( $user->ID, $local_plan_id );

		if ( null === $user_membership ) {
			// Create a new membership if it doesn't exist and link it to the remote membership.
			$user_membership = wc_memberships_create_user_membership(
				[
					'plan_id' => $local_plan_id,
					'user_id' => $user->ID,
				]
			);
		}

		if ( is_wp_error( $user_membership ) ) {
			Debugger::log( 'Error creating membership plan: ' . $user_membership->get_error_message() );
			return;
		}

		if ( ! $user_membership instanceof WC_Memberships_User_Membership ) {
			Debugger::log( 'Error creating membership plan' );
			return;
		}

		$this->apply_membership_update( $user_membership );
	}

	/**
	 * Transfer a managed membership from the previous owner to the new owner.
	 *
	 * Finds the existing membership by remote_id and reassigns it.
	 * Falls back to creating a new membership if the existing one can't be found.
	 *
	 * @param \WP_User $new_user The new owner.
	 * @param int      $local_plan_id The local plan ID.
	 * @param string   $previous_email The previous owner's email.
	 * @return void
	 */
	private function transfer_membership( $new_user, $local_plan_id, $previous_email ) {
		global $wpdb;

		Debugger::log( 'Processing membership transfer from ' . $previous_email . ' to ' . $new_user->user_email );

		$remote_membership_id = $this->get_membership_id();

		// Find the existing managed membership by remote_id.
		$existing_membership_id = $wpdb->get_var( // phpcs:ignore
			$wpdb->prepare(
				"SELECT post_id FROM $wpdb->postmeta
				WHERE meta_key = %s AND meta_value = %s
				AND post_id IN (
					SELECT post_id FROM $wpdb->postmeta
					WHERE meta_key = %s AND meta_value = %s
				)
				AND post_id IN (
					SELECT ID FROM $wpdb->posts WHERE post_type = 'wc_user_membership' AND post_parent = %d
				)
				AND post_id IN (
					SELECT post_id FROM $wpdb->postmeta
					WHERE meta_key = %s
				)",
				Memberships_Admin::REMOTE_ID_META_KEY,
				$remote_membership_id,
				Memberships_Admin::SITE_URL_META_KEY,
				$this->get_site(),
				$local_plan_id,
				Memberships_Admin::NETWORK_MANAGED_META_KEY
			)
		);

		if ( ! $existing_membership_id ) {
			Debugger::log( 'Managed membership not found by remote_id, falling back to previous owner lookup.' );

			// Try finding by previous owner + plan.
			$previous_user = get_user_by( 'email', $previous_email );
			if ( $previous_user ) {
				$previous_membership = wc_memberships_get_user_membership( $previous_user->ID, $local_plan_id );
				if ( $previous_membership ) {
					$existing_membership_id = $previous_membership->get_id();
				}
			}
		}

		if ( ! $existing_membership_id ) {
			Debugger::log( 'No existing membership found to transfer, creating new one.' );

			$user_membership = wc_memberships_create_user_membership(
				[
					'plan_id' => $local_plan_id,
					'user_id' => $new_user->ID,
				]
			);

			if ( is_wp_error( $user_membership ) || ! $user_membership instanceof WC_Memberships_User_Membership ) {
				Debugger::log( 'Error creating membership for transfer.' );
				return;
			}

			$this->apply_membership_update( $user_membership );
			return;
		}

		// Skip team-owned memberships: their ownership lives on the team post's `_member_id`,
		// the linked subscription's `_customer_user`, and team_member user meta – rewriting only
		// `post_author` here would silently desync all of those and break renewal dispatch.
		// Team ownership transfers must be handled at the team level, not the user_membership level.
		$team_id = get_post_meta( $existing_membership_id, '_team_id', true );
		if ( ! empty( $team_id ) ) {
			Debugger::log(
				sprintf(
					'Skipping transfer of team-owned membership #%d (team #%s) from %s to %s. Team memberships cannot be transferred via post_author alone.',
					$existing_membership_id,
					$team_id,
					$previous_email,
					$new_user->user_email
				)
			);
			return;
		}

		// Reassign the membership to the new owner.
		$updated_post_id = wp_update_post(
			[
				'ID'          => $existing_membership_id,
				'post_author' => $new_user->ID,
			],
			true
		);

		if ( is_wp_error( $updated_post_id ) || ! $updated_post_id ) {
			$error_message = is_wp_error( $updated_post_id ) ? $updated_post_id->get_error_message() : 'Unknown error';
			Debugger::log( 'Error transferring membership: failed to update post author. ' . $error_message );
			return;
		}

		$user_membership = wc_memberships_get_user_membership( $existing_membership_id );

		if ( ! $user_membership instanceof WC_Memberships_User_Membership ) {
			Debugger::log( 'Error retrieving membership after transfer.' );
			return;
		}

		$user_membership->add_note(
			sprintf(
				// translators: 1: previous owner email, 2: new owner email, 3: site URL.
				__( 'Membership transferred from %1$s to %2$s via Newspack Network. Propagated from %3$s.', 'newspack-network' ),
				$previous_email,
				$new_user->user_email,
				$this->get_site()
			)
		);

		$this->apply_membership_update( $user_membership );

		Debugger::log( 'Membership transferred successfully.' );
	}

	/**
	 * Apply status, end date, and managed meta to a membership.
	 *
	 * @param WC_Memberships_User_Membership $user_membership The membership to update.
	 * @return void
	 */
	private function apply_membership_update( $user_membership ) {
		$status     = $this->get_new_status();
		$is_managed = get_post_meta( $user_membership->get_id(), Memberships_Admin::NETWORK_MANAGED_META_KEY, true );

		if ( '__deleted' === $status ) {
			wp_delete_post( $user_membership->get_id(), true );
			Debugger::log( 'User membership deleted' );
			return;
		} elseif ( in_array( $status, [ 'cancelled', 'expired' ], true ) && $is_managed ) {
			// If the membership is being cancelled or expired, and the membership is managed, we remove the managed meta fields.
			// This is to allow the membership to be re-initiated from another site in the network.
			delete_post_meta( $user_membership->get_id(), Memberships_Admin::NETWORK_MANAGED_META_KEY );
			delete_post_meta( $user_membership->get_id(), Memberships_Admin::REMOTE_ID_META_KEY );
			delete_post_meta( $user_membership->get_id(), Memberships_Admin::SITE_URL_META_KEY );
			$user_membership->add_note(
				sprintf(
					// translators: %s is the site URL.
					__( 'Membership has been unlinked via Newspack Network. Unlinked from %s', 'newspack-network' ),
					$this->get_site()
				)
			);

		} elseif ( ! $is_managed ) {
			// Otherwise, if the membership is not managed, reset the relevant meta fields.
			update_post_meta( $user_membership->get_id(), Memberships_Admin::NETWORK_MANAGED_META_KEY, true );
			update_post_meta( $user_membership->get_id(), Memberships_Admin::REMOTE_ID_META_KEY, $this->get_membership_id() );
			update_post_meta( $user_membership->get_id(), Memberships_Admin::SITE_URL_META_KEY, $this->get_site() );
		}
		$user_membership->update_status( $status );
		$user_membership->set_end_date( $this->get_end_date() ?? '' );
		$user_membership->add_note(
			sprintf(
				// translators: 1: membership status, 2: site URL.
				__( 'Membership status updated to %1$s via Newspack Network. Propagated from %2$s.', 'newspack-network' ),
				$status,
				$this->get_site()
			)
		);

		Debugger::log( 'User membership updated' );
	}

	/**
	 * Get the network id of the membership's pan
	 *
	 * @return ?string
	 */
	public function get_plan_network_id() {
		return $this->data->plan_network_id ?? null;
	}

	/**
	 * Get the new status of the membership
	 *
	 * @return ?string
	 */
	public function get_new_status() {
		return $this->data->new_status ?? null;
	}

	/**
	 * Get the original membership id
	 *
	 * @return ?string
	 */
	public function get_membership_id() {
		return $this->data->membership_id ?? null;
	}

	/**
	 * Get the original end date of the membership
	 *
	 * @return ?string
	 */
	public function get_end_date() {
		return $this->data->end_date ?? null;
	}

	/**
	 * Get the previous owner's email (set during ownership transfers).
	 *
	 * @return ?string
	 */
	public function get_previous_email() {
		return $this->data->previous_email ?? null;
	}
}
