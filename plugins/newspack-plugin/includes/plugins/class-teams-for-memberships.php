<?php
/**
 * Teams for Memberships integration class.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

use Newspack\Donations;
use Newspack\Reader_Activation\Sync\Woocommerce as Sync_WooCommerce;
use Newspack\Reader_Activation\Sync\Metadata as Sync_Metadata;
use Newspack\Reader_Activation\Contact_Sync;
use Newspack\Reader_Activation\Integrations;

/**
 * Main class.
 */
class Teams_For_Memberships {

	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_listeners' ] );
		add_action( 'init', [ __CLASS__, 'register_handlers' ], 11 );
		add_filter( 'newspack_ras_metadata_keys', [ __CLASS__, 'add_teams_metadata_keys' ] );
		add_filter( 'newspack_esp_sync_contact', [ __CLASS__, 'handle_esp_sync_contact' ] );
		add_filter( 'newspack_my_account_disabled_pages', [ __CLASS__, 'enable_members_area_for_team_members' ] );
		add_action( 'woocommerce_checkout_subscription_created', [ __CLASS__, 'update_team_subscription_on_resubscribe' ], 21, 2 );
		add_filter( 'wc_memberships_for_teams_determine_order_item_action', [ __CLASS__, 'restore_team_meta_on_renewal' ], 10, 2 );
		// Priority 20 so this runs after Teams' own subscription integration (priority 10), which is a no-op for non-subscription teams.
		add_action( 'wc_memberships_for_teams_add_team_member', [ __CLASS__, 'sync_member_end_date_to_team' ], 20, 3 );
	}

	/**
	 * Register listeners.
	 */
	public static function register_listeners() {
		/**
		 * When a team is created.
		 */
		Data_Events::register_listener(
			'wc_memberships_for_teams_team_created',
			'team_created',
			/**
			 * See: SkyVerge\WooCommerce\Memberships\Teams\Teams_Handler.
			 *
			 * @param \SkyVerge\WooCommerce\Memberships\Teams\Team $team The team that was just created.
			 * @param bool $updating True if updating, false if a newly created team.
			 */
			function( $team, $updating ) {
				// Only on new team creation.
				if ( $updating ) {
					return null;
				}
				$owner = $team->get_owner();
				return [
					'user_id' => $owner->ID,
					'email'   => $owner->data->user_email,
					'team_id' => $team->get_id(),
				];
			}
		);

		/**
		 * When a member is added to a team.
		 */
		Data_Events::register_listener(
			'wc_memberships_for_teams_add_team_member',
			'team_member_added',
			/**
			 * See: SkyVerge\WooCommerce\Memberships\Teams\Team.
			 *
			 * @param \SkyVerge\WooCommerce\Memberships\Teams\Team_Member $member The team member instance.
			 * @param \SkyVerge\WooCommerce\Memberships\Teams\Team $team The team instance.
			 * @param \WC_Memberships_User_Membership $user_membership The related user membership instance.
			 */
			function( $member, $team, $membership ) {
				return [
					'user_id'       => $member->get_id(),
					'email'         => $member->get_email(),
					'team_id'       => $team->get_id(),
					'membership_id' => $membership->get_id(),
				];
			}
		);
	}

	/**
	 * Register handlers.
	 */
	public static function register_handlers() {
		if ( ! Contact_Sync::has_one_syncable_integration() || ! self::is_enabled() ) {
			return;
		}
		Data_Events::register_handler( [ __CLASS__, 'sync_owner' ], 'team_created' );
		Data_Events::register_handler( [ __CLASS__, 'sync_member' ], 'team_member_added' );
		Data_Events::register_handler( [ __CLASS__, 'reader_logged_in' ], 'reader_logged_in' );
	}

	/**
	 * Sync team owner data on team creation.
	 *
	 * @param int   $timestamp Timestamp of the event.
	 * @param array $data      Data associated with the event.
	 * @param int   $client_id ID of the client that triggered the event.
	 */
	public static function sync_owner( $timestamp, $data, $client_id ) {
		if ( empty( $data['email'] ) || empty( $data['user_id'] ) ) {
			return;
		}

		$contact = Sync_WooCommerce::get_contact_from_customer( new \WC_Customer( $data['user_id'] ) );
		Contact_Sync::sync( $contact, 'WooCommerce Memberships for Teams: team created' );
	}

	/**
	 * Sync team member data on being added to a team.
	 *
	 * @param int   $timestamp Timestamp of the event.
	 * @param array $data      Data associated with the event.
	 * @param int   $client_id ID of the client that triggered the event.
	 */
	public static function sync_member( $timestamp, $data, $client_id ) {
		if ( empty( $data['email'] ) || empty( $data['user_id'] ) ) {
			return;
		}

		$contact = Sync_WooCommerce::get_contact_from_customer( new \WC_Customer( $data['user_id'] ) );
		Contact_Sync::sync( $contact, 'WooCommerce Memberships for Teams: user added to team' );
	}

	/**
	 * Sync reader data on login.
	 *
	 * @param int   $timestamp Timestamp of the event.
	 * @param array $data      Data associated with the event.
	 * @param int   $client_id ID of the client that triggered the event.
	 */
	public static function reader_logged_in( $timestamp, $data, $client_id ) {
		if ( empty( $data['email'] ) || empty( $data['user_id'] ) || ! function_exists( 'wc_memberships_for_teams_get_teams' ) ) {
			return;
		}

		$customer = new \WC_Customer( $data['user_id'] );

		// If user has orders or is not a Woo team member, don't need to sync them.
		if ( 0 < $customer->get_order_count() || empty( \wc_memberships_for_teams_get_teams( $data['user_id'], [ 'role' => 'member' ] ) ) ) {
			return;
		}
		$contact = Sync_WooCommerce::get_contact_from_customer( $customer );

		Contact_Sync::sync( $contact, 'RAS Reader login' );
	}

	/**
	 * Check if Teams for Memberships is enabled.
	 *
	 * @return bool True if enabled, false otherwise.
	 */
	private static function is_enabled() {
		return Donations::is_platform_wc() && class_exists( 'WC_Memberships_For_Teams_Loader' );
	}

	/**
	 * Add Teams metadata keys.
	 *
	 * @param array $metadata_keys Metadata keys.
	 * @return array Metadata keys.
	 */
	public static function add_teams_metadata_keys( $metadata_keys ) {
		if ( self::is_enabled() ) {
			$metadata_keys['woo_team'] = 'Woo Team';
		}
		return $metadata_keys;
	}

	/**
	 * Add Teams metadata to contact data.
	 *
	 * @param array $contact Contact data.
	 *
	 * @return array Updated contact data.
	 */
	public static function handle_esp_sync_contact( $contact ) {
		if ( ! self::is_enabled() || ! function_exists( 'wc_memberships_for_teams_get_teams' ) ) {
			return $contact;
		}

		$esp = Integrations::get_integration( 'esp' );
		if ( ! $esp ) {
			return $contact;
		}
		$filtered_enabled_fields = $esp->filter_enabled_outgoing_fields( [ 'woo_team' ] );

		if ( empty( $contact['email'] ) ) {
			return $contact;
		}

		$user = \get_user_by( 'email', $contact['email'] );

		if ( ! $user ) {
			return $contact;
		}

		if ( ! isset( $contact['metadata'] ) ) {
			$contact['metadata'] = [];
		}

		$existing_membership_teams = \wc_memberships_for_teams_get_teams( $user->ID );
		if ( empty( $existing_membership_teams ) ) {
			return $contact;
		}

		if ( empty( Sync_Metadata::get_key_value( 'membership_status', $contact['metadata'] ) ) ) {
			$contact['metadata']['membership_status'] = 'team member';
		}

		if ( count( $filtered_enabled_fields ) === 0 ) {
			return $contact;
		}

		$team_slugs = [];
		foreach ( $existing_membership_teams as $team ) {
			$team_slugs[] = $team->get_slug();
		}
		$team_slugs = implode( ',', $team_slugs );
		if ( $team_slugs ) {
			$contact['metadata']['woo_team'] = $team_slugs;
		}

		return $contact;
	}

	/**
	 * Enable Members Area for team members only. Team owners/managers get access to the "Teams" menu instead.
	 *
	 * @param array $disabled_wc_menu_items Disabled WooCommerce menu items.
	 *
	 * @return array Updated disabled WooCommerce menu items.
	 */
	public static function enable_members_area_for_team_members( $disabled_wc_menu_items ) {
		if ( ! function_exists( 'wc_memberships_for_teams_get_teams' ) ) {
			return $disabled_wc_menu_items;
		}
		if (
			in_array( 'members-area', $disabled_wc_menu_items, true ) &&
			! empty( \wc_memberships_for_teams_get_teams( \get_current_user_id(), [ 'role' => 'member' ] ) )
		) {
			$disabled_wc_menu_items = array_values( array_diff( $disabled_wc_menu_items, [ 'members-area' ] ) );
		}
		return $disabled_wc_menu_items;
	}


	/**
	 * Updates related subscription data on resubscribe from expired subscription.
	 *
	 * This function replicates the behavior from teams but for expired subscriptions.
	 * Teams does not handle expired subscriptions on resubscribe by default but since we force
	 * subscriptions to expire on failed renewals, we need to handle this case.
	 *
	 * @param WC_Subscription $new_subscription  the new subscription object.
	 * @param \WC_Order       $resubscribe_order the order that created a new subscription.
	 */
	public static function update_team_subscription_on_resubscribe( $new_subscription, $resubscribe_order ) {
		if ( ! method_exists( '\SkyVerge\WooCommerce\Memberships\Teams\Integrations\Subscriptions', 'get_teams_from_subscription' ) ) {
			return;
		}

		if ( ! method_exists( '\WC_Memberships_Integration_Subscriptions_User_Membership', 'set_subscription_id' ) ) {
			return;
		}

		$new_order_id        = $resubscribe_order->get_id();
		$new_subscription_id = $new_subscription->get_id();
		$old_subscription_id = $new_subscription_id > 0 ? $new_subscription->get_meta( '_subscription_resubscribe' ) : 0;
		$old_subscription    = $old_subscription_id > 0 ? wcs_get_subscription( $old_subscription_id ) : null;

		if ( $old_subscription && in_array( $old_subscription->get_status(), [ 'expired' ] ) ) {
			$team_subscriptions = new \SkyVerge\WooCommerce\Memberships\Teams\Integrations\Subscriptions();
			$existing_teams     = $team_subscriptions->get_teams_from_subscription( $old_subscription_id );
			if ( ! empty( $existing_teams ) ) {
				foreach ( $existing_teams as $existing_team ) {
					// update the team's subscription link and the order link.
					update_post_meta( $existing_team->get_id(), '_subscription_id', $new_subscription_id );
					update_post_meta( $existing_team->get_id(), '_order_id', $new_order_id );
					// Update end dates for all team memberships before reactivating.
					foreach ( $existing_team->get_user_memberships() as $user_membership ) {
						$user_membership->set_end_date( $new_subscription->get_date( 'end' ) );
						// set the membership's subscription ID.
						$subscription_membership = new \WC_Memberships_Integration_Subscriptions_User_Membership( $user_membership->post );
						$subscription_membership->set_subscription_id( $new_subscription->get_id() );
						// bail if not associated with an order.
						if ( ! $resubscribe_order instanceof \WC_Order ) {
							continue;
						}
						$note    = '';
						$product = $existing_team->get_product();
						$subscription_membership->set_order_id( $new_order_id );
						if ( $product instanceof \WC_Product ) {
							$subscription_membership->set_product_id( $product->get_id() );
							$note = sprintf(
								/* translators: Placeholders: %1$s - subscription product name, %2%s - order number */
								__( 'Membership re-activated due to subscription re-purchase (%1$s, Order %2$s).', 'newspack-plugin' ),
								$product->get_title(),
								'<a href="' . esc_url( admin_url( 'post.php?post=' . $new_order_id . '&action=edit' ) ) . '" >' . esc_html( $new_order_id ) . '</a>'
							);
						}
						if ( $subscription_membership->has_status( [ 'pending', 'cancelled' ] ) ) {
							$subscription_membership->update_status( 'active', $note );
						}
					}
				}
			}
		}
	}

	/**
	 * Restore stripped team order-item meta on renewal orders.
	 *
	 * SkyVerge's Teams plugin dispatches team actions from order item meta:
	 * `_wc_memberships_for_teams_team_id`, `_wc_memberships_for_teams_team_renewal`,
	 * `_wc_memberships_for_teams_team_seat_change`. WC Subscriptions copies these onto
	 * renewal order items at generation time, but if an admin manually replaces a
	 * subscription line item in wp-admin, the replacement row has no such meta. On the
	 * next renewal, Teams falls through to the `create` branch and creates a duplicate
	 * team for an already-linked subscription.
	 *
	 * This filter runs on `wc_memberships_for_teams_determine_order_item_action`: when
	 * the proposed action is `create` and the order is a subscription renewal whose
	 * parent subscription already has a linked team for the same product, we restore
	 * the stripped meta on the item and force the action to `renew`.
	 *
	 * @see https://linear.app/a8c/issue/NPPM-2741
	 *
	 * @param string         $action The action determined by SkyVerge Teams (create|renew|seat_change).
	 * @param \WC_Order_Item $item   The order item being processed.
	 * @return string The possibly-rewritten action.
	 */
	public static function restore_team_meta_on_renewal( $action, $item ) {
		if ( 'create' !== $action ) {
			return $action;
		}
		if ( ! function_exists( 'wcs_order_contains_renewal' ) || ! function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
			return $action;
		}
		if ( ! method_exists( '\SkyVerge\WooCommerce\Memberships\Teams\Integrations\Subscriptions', 'get_teams_from_subscription' ) ) {
			return $action;
		}
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return $action;
		}

		$order = $item->get_order();
		if ( ! $order instanceof \WC_Order || ! wcs_order_contains_renewal( $order ) ) {
			return $action;
		}

		$subscriptions      = wcs_get_subscriptions_for_renewal_order( $order );
		$team_subscriptions = new \SkyVerge\WooCommerce\Memberships\Teams\Integrations\Subscriptions();
		// Variation line items expose the parent product id via get_product_id() and the variation
		// id via get_variation_id(). Teams may have stored either on the team, so match against both.
		$item_product_ids = array_filter(
			[ (int) $item->get_product_id(), (int) $item->get_variation_id() ]
		);

		foreach ( $subscriptions as $subscription ) {
			$teams = $team_subscriptions->get_teams_from_subscription( $subscription->get_id() );
			if ( empty( $teams ) ) {
				continue;
			}
			foreach ( $teams as $team ) {
				if ( ! in_array( (int) $team->get_product_id(), $item_product_ids, true ) ) {
					continue;
				}
				$item->update_meta_data( '_wc_memberships_for_teams_team_id', $team->get_id() );
				$item->update_meta_data( '_wc_memberships_for_teams_team_renewal', true );
				// Clear any stale seat-change flag so the dispatcher unambiguously treats this as a renewal.
				$item->delete_meta_data( '_wc_memberships_for_teams_team_seat_change' );
				$item->save();
				return 'renew';
			}
		}

		return $action;
	}

	/**
	 * Fill in a member's user-membership end date from the team when it has none.
	 *
	 * Teams propagates the team end date to a member's user membership when the member already had
	 * an existing membership (see Team::add_member()), or when the team is tied to a WooCommerce
	 * subscription (see the Teams Subscriptions integration, which bails for non-subscription
	 * teams). For manually-managed teams that have a fixed end date but no subscription, a newly
	 * created member membership is left with the plan-relative end date, which resolves to empty
	 * for unlimited/subscription-typed plans. The member then never expires even after the team
	 * membership lapses.
	 *
	 * This fills that specific gap: when the member's user membership has no end date at all, it
	 * inherits the team's. We deliberately only fill a *missing* end date and never override one
	 * that is already set -- whether by the plan's access length, a separately purchased individual
	 * membership, or upstream Team::add_member() (which reconciles existing memberships and their
	 * status on its own). That keeps us from fighting fixed-length plans' midnight-snapped dates,
	 * shortening a longer independently-held membership, or duplicating upstream's membership note.
	 *
	 * Calling set_end_date() also (re)schedules the per-membership expiry event so the member
	 * expires on time on their own; is_active() lazily expires the membership if the team end is
	 * already in the past, so no status change is needed here (and we avoid the synchronous ESP
	 * list mutations a status change would fire during a possibly-bulk member add).
	 *
	 * @see https://linear.app/a8c/issue/NPPM-2932
	 *
	 * @param \SkyVerge\WooCommerce\Memberships\Teams\Team_Member $member          The team member instance.
	 * @param \SkyVerge\WooCommerce\Memberships\Teams\Team        $team            The team instance.
	 * @param \WC_Memberships_User_Membership                     $user_membership The related user membership instance.
	 */
	public static function sync_member_end_date_to_team( $member, $team, $user_membership ): void {
		if (
			! is_a( $team, '\SkyVerge\WooCommerce\Memberships\Teams\Team' ) ||
			! is_a( $user_membership, '\WC_Memberships_User_Membership' ) ||
			! method_exists( $team, 'get_membership_end_date' )
		) {
			return;
		}

		// Only fill a *missing* end date. Anything already set is owned by the plan, an independent
		// membership, or upstream Team::add_member(); overriding it would fight fixed-length plans'
		// midnight snapping and could shorten a longer independently-held membership.
		if ( ! empty( $user_membership->get_end_date( 'timestamp' ) ) ) {
			return;
		}

		$team_end = $team->get_membership_end_date( 'timestamp' );

		// Unlimited team (no end date): there is nothing to enforce. The is_numeric() check is
		// belt-and-suspenders for this access-control path: get_membership_end_date( 'timestamp' )
		// returns int|null today, but if a future upstream change returned a date string, casting it
		// to int below would collapse it to a ~1970 timestamp and wrongly expire the member -- so we
		// bail instead.
		if ( empty( $team_end ) || ! is_numeric( $team_end ) ) {
			return;
		}

		// Pass a MySQL/UTC date string for consistency with this file's sibling set_end_date() call.
		$user_membership->set_end_date( gmdate( 'Y-m-d H:i:s', (int) $team_end ) );

		$user_membership->add_note( __( 'Membership end date synced to the team membership expiration date.', 'newspack-plugin' ) );
	}
}

Teams_For_Memberships::init();
