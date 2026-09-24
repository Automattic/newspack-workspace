<?php
/**
 * Newspack Network Woo Membership events
 *
 * @package Newspack
 */

namespace Newspack_Network\Woocommerce_Memberships;

use Newspack\Data_Events;
use WC_Memberships_Membership_Plan;

/**
 * Fire the events for Woocommerce Memberships
 *
 * These events are built using the experience gathered on newspack-newsletters woo-membership integration.
 * It showed us that we can't rely only on the woocommerce_memberships_user_membership_status_changed hook to fire the events.
 * It won't catch membership creations or deletions, for example.
 */
class Events {

	/**
	 * Used to pause events triggering when ingesting events from other sites to avoid an infinite loop of events
	 *
	 * @var boolean
	 */
	public static $pause_events = false;

	/**
	 * Initialize this class and register hooks
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_listeners' ] );
	}

	/**
	 * Register the listeners to the Newspack Data Events API
	 *
	 * @return void
	 */
	public static function register_listeners() {
		if ( ! class_exists( 'Newspack\Data_Events' ) ) {
			return;
		}

		Data_Events::register_listener( 'wc_memberships_user_membership_status_changed', 'newspack_network_woo_membership_updated', [ __CLASS__, 'membership_status_changed' ] );
		Data_Events::register_listener( 'wc_memberships_user_membership_saved', 'newspack_network_woo_membership_updated', [ __CLASS__, 'membership_saved' ] );
		Data_Events::register_listener( 'wc_memberships_user_membership_deleted', 'newspack_network_woo_membership_updated', [ __CLASS__, 'membership_deleted' ] );
		Data_Events::register_listener( 'wc_memberships_user_membership_transferred', 'newspack_network_woo_membership_updated', [ __CLASS__, 'membership_transferred' ] );
		Data_Events::register_listener( 'newspack_network_save_membership_plan', 'newspack_network_membership_plan_updated', [ __CLASS__, 'membership_plan_updated' ] );
	}

	/**
	 * Transforms the data of the wc_memberships_user_membership_status_changed to trigger the newspack_network_woo_membership_updated data event
	 *
	 * Fires only when the membership status changes to a non-active status to avoid multiple events for the same membership.
	 * The occasions when a membership is set to active or updates is handled by the wc_memberships_user_membership_saved hook.
	 *
	 * @param WC_Memberships_User_Membership $user_membership The User Membership object.
	 * @param string                         $old_status old status, without the `wcm-` prefix.
	 * @param string                         $new_status new status, without the `wcm-` prefix.
	 * @return array
	 */
	public static function membership_status_changed( $user_membership, $old_status, $new_status ) {

		if ( self::$pause_events ) {
			return;
		}

		$status_considered_active = wc_memberships()->get_user_memberships_instance()->get_active_access_membership_statuses();

		if ( in_array( $new_status, $status_considered_active ) ) {
			return;
		}

		$user = $user_membership->get_user();
		if ( ! $user ) {
			return;
		}
		$user_email = $user->user_email;
		$plan_id    = $user_membership->get_plan()->get_id();

		$plan_network_id = get_post_meta( $plan_id, Admin::NETWORK_ID_META_KEY, true );
		if ( ! $plan_network_id ) {
			return;
		}

		return [
			'email'           => $user_email,
			'user_id'         => $user->ID,
			'plan_network_id' => $plan_network_id,
			'membership_id'   => $user_membership->get_id(),
			'new_status'      => $new_status,
			'end_date'        => $user_membership->get_end_date(),
		];
	}

	/**
	 * Remove lists that require a membership plan when the membership is cancelled
	 *
	 * @param WC_Memberships_User_Membership $user_membership The User Membership object.
	 * @return array
	 */
	public static function membership_deleted( $user_membership ) {
		if ( self::$pause_events ) {
			return;
		}

		$user = $user_membership->get_user();
		if ( ! $user ) {
			return;
		}
		$plan = $user_membership->get_plan();
		if ( ! $plan ) {
			// Plan post was already deleted (e.g. via `wp_delete_auto_drafts` cascading into membership deletion).
			return;
		}
		$user_email = $user->user_email;
		$plan_id    = $plan->get_id();

		$plan_network_id = get_post_meta( $plan_id, Admin::NETWORK_ID_META_KEY, true );
		if ( ! $plan_network_id ) {
			return;
		}

		return [
			'email'           => $user_email,
			'user_id'         => $user->ID,
			'plan_network_id' => $plan_network_id,
			'membership_id'   => $user_membership->get_id(),
			'new_status'      => '__deleted',
		];
	}

	/**
	 * Transforms the data of the wc_memberships_user_membership_saved to trigger the newspack_network_woo_membership_updated data event
	 *
	 * @param \WC_Memberships_Membership_Plan $plan the plan that user was granted access to.
	 * @param array                           $args {
	 *     Array of User Membership arguments.
	 *
	 *     @type int $user_id the user ID the membership is assigned to.
	 *     @type int $user_membership_id the user membership ID being saved.
	 *     @type bool $is_update whether this is a post update or a newly created membership.
	 * }
	 * @return array
	 */
	public static function membership_saved( $plan, $args ) {

		if ( self::$pause_events ) {
			return;
		}

		// When creating the membership via admin panel, this hook is called once before the membership is actually created.
		if ( ! $plan instanceof WC_Memberships_Membership_Plan ) {
			return;
		}

		$status_considered_active = wc_memberships()->get_user_memberships_instance()->get_active_access_membership_statuses();

		$user_membership = new \WC_Memberships_User_Membership( $args['user_membership_id'] );

		if ( ! in_array( $user_membership->get_status(), $status_considered_active, true ) ) {
			return;
		}

		$user_id = $args['user_id'] ?? false;
		if ( ! $user_id ) {
			return;
		}
		$user = get_user_by( 'ID', $user_id );
		if ( ! $user ) {
			return;
		}

		$user_email = $user->user_email;
		$plan_id    = $plan->get_id();

		$plan_network_id = get_post_meta( $plan_id, Admin::NETWORK_ID_META_KEY, true );
		if ( ! $plan_network_id ) {
			return;
		}

		return [
			'email'           => $user_email,
			'user_id'         => $user_id,
			'plan_network_id' => $plan_network_id,
			'membership_id'   => $user_membership->get_id(),
			'new_status'      => $user_membership->get_status(),
			'end_date'        => $user_membership->get_end_date(),
		];
	}

	/**
	 * Transforms the data of the wc_memberships_user_membership_transferred hook to trigger the newspack_network_woo_membership_updated data event
	 *
	 * @param \WC_Memberships_User_Membership $user_membership The User Membership object that was transferred.
	 * @param \WP_User                        $new_owner The new owner of the membership.
	 * @param \WP_User                        $previous_owner The previous owner of the membership.
	 * @return array|void
	 */
	public static function membership_transferred( $user_membership, $new_owner, $previous_owner ) {
		if ( self::$pause_events ) {
			return;
		}

		$plan_id = $user_membership->get_plan()->get_id();

		$plan_network_id = get_post_meta( $plan_id, Admin::NETWORK_ID_META_KEY, true );
		if ( ! $plan_network_id ) {
			return;
		}

		return [
			'email'           => $new_owner->user_email,
			'user_id'         => $new_owner->ID,
			'plan_network_id' => $plan_network_id,
			'membership_id'   => $user_membership->get_id(),
			'new_status'      => $user_membership->get_status(),
			'end_date'        => $user_membership->get_end_date(),
			'previous_email'  => $previous_owner->user_email,
		];
	}

	/**
	 * Triggers a data event when the membership plan is updated
	 *
	 * @param int $plan_id The ID of the membership plan.
	 * @return array
	 */
	public static function membership_plan_updated( $plan_id ) {
		$plan = new WC_Memberships_Membership_Plan( $plan_id );
		$data = [
			'id'         => $plan->get_id(),
			'network_id' => get_post_meta( $plan->get_id(), Admin::NETWORK_ID_META_KEY, true ),
			'name'       => $plan->get_name(),
			'slug'       => $plan->get_slug(),
			'products'   => [],
		];

		$products = $plan->get_products();

		foreach ( $products as $product ) {
			$data['products'][ $product->get_id() ] = [
				'id'   => $product->get_id(),
				'name' => $product->get_name(),
				'slug' => $product->get_slug(),
			];
		}

		return $data;
	}
}
