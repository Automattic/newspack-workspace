<?php
/**
 * Newspack Newsletters Settings Page
 *
 * @package Newspack
 */

namespace Newspack_Newsletters\Plugins;

use Newspack\Newsletters\Subscription_List;
use Newspack\Newsletters\Subscription_Lists;
use Newspack_Newsletters;
use Newspack_Newsletters_Contacts;
use Newspack_Newsletters_Logger;

defined( 'ABSPATH' ) || exit;

require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . '/includes/plugins/woocommerce-memberships/class-sync-membership-tied-subscribers-cli.php';

/**
 * Manages Settings page.
 */
class Woocommerce_Memberships {

	/**
	 * The user meta key that stores the list IDs that the user is subscribed to when their memberships are deactivated.
	 *
	 * This is used to restore the user's subscriptions when their membership is reactivated.
	 *
	 * @var string
	 */
	const SUBSCRIBED_ON_DEACTIVATION_META_KEY = 'np_newsletters_lists_on_membership_deactivation';

	/**
	 * Holds the ID of the user in the scope of the current request.
	 *
	 * When a user is granted membership to a plan that requires a registration, the user is not logged in yet,
	 * so we use this information to add the user to the lists when the user is created.
	 *
	 * @var ?int
	 */
	protected static $user_id_in_scope;

	/**
	 * Holds the previous statuses of the memberships that is being updated in the request.
	 *
	 * @var array
	 */
	protected static $previous_statuses = [];

	/**
	 * Initialize the class
	 */
	public static function init() {
		add_action( 'plugins_loaded', [ __CLASS__, 'init_hooks' ] );
	}

	/**
	 * Initialize the hooks after all plugins are loaded
	 */
	public static function init_hooks() {
		add_filter( 'newspack_newsletters_contact_lists', [ __CLASS__, 'filter_lists' ] );
		add_filter( 'newspack_newsletters_subscription_block_available_lists', [ __CLASS__, 'filter_lists' ] );
		add_filter( 'newspack_newsletters_manage_newsletters_available_lists', [ __CLASS__, 'filter_lists_objects' ] );
		add_filter( 'newspack_post_registration_newsletters_lists', [ __CLASS__, 'filter_lists_objects' ], 10, 2 );
		add_filter( 'newspack_auth_form_newsletters_lists', [ __CLASS__, 'filter_lists_objects' ] );
		add_action( 'wc_memberships_user_membership_status_changed', [ __CLASS__, 'handle_membership_status_change' ], 10, 3 );
		add_action( 'wc_memberships_user_membership_saved', [ __CLASS__, 'add_user_to_lists' ], 10, 2 );
		add_action( 'wc_memberships_user_membership_deleted', [ __CLASS__, 'deleted_membership' ] );
	}

	/**
	 * Is WC Memberships enabled?
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		if ( class_exists( 'WC_Memberships_Loader' ) && function_exists( 'wc_memberships_is_post_content_restricted' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Keep users from being added to lists that require a membership plan they dont have
	 * Also filters lists that require a membership plan to be displayed in the subscription block and in the Manage Newsletters page in My Account
	 *
	 * @param array  $lists         The List IDs.
	 * @param string $email_address The email address of the user to check against. Optional.
	 * @return array
	 */
	public static function filter_lists( $lists, $email_address = '' ) {
		if ( ! self::is_enabled() || ! is_array( $lists ) || empty( $lists ) ) {
			return $lists;
		}
		$lists = array_filter(
			$lists,
			function ( $list ) use ( $email_address ) {
				$list_object = Subscription_List::from_public_id( $list );
				if ( ! $list_object ) {
					return false;
				}

				$is_post_restricted = \wc_memberships_is_post_content_restricted( $list_object->get_id() );

				if ( ! $is_post_restricted ) {
					return true;
				}

				$user_id = self::$user_id_in_scope;
				if ( ! $user_id ) {
					if ( is_email( $email_address ) ) {
						$user_id = get_user_by( 'email', $email_address )->ID;
					} else {
						$user_id = get_current_user_id();
					}
				}

				return \wc_memberships_user_can( $user_id, 'view', [ 'post' => $list_object->get_id() ] );
			}
		);
		return array_values( $lists );
	}

	/**
	 * Receives an array of Lists and returns only the ones that the user has access to
	 *
	 * @param array  $lists         An array of lists in which the key are the list IDs.
	 * @param string $email_address The email address of the user to check against. Optional.
	 * @return array
	 */
	public static function filter_lists_objects( $lists, $email_address = '' ) {
		if ( ! self::is_enabled() || ! is_array( $lists ) || empty( $lists ) ) {
			return $lists;
		}

		$list_ids = self::filter_lists( array_keys( $lists ), $email_address );

		return array_filter(
			$lists,
			function ( $list_id ) use ( $list_ids ) {
				return in_array( $list_id, $list_ids );
			},
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Handle membership status updates and remove lists that require a membership plan when the membership is cancelled
	 *
	 * @param WC_Memberships_User_Membership $user_membership The User Membership object.
	 * @param string                         $old_status old status, without the `wcm-` prefix.
	 * @param string                         $new_status new status, without the `wcm-` prefix.
	 * @return void
	 */
	public static function handle_membership_status_change( $user_membership, $old_status, $new_status ) {
		$user = $user_membership->get_user();
		if ( ! $user ) {
			return;
		}
		$user_email = $user->user_email;

		Newspack_Newsletters_Logger::log(
			sprintf(
				'Membership status for %s changed from %s to %s',
				$user_email,
				$old_status,
				$new_status
			)
		);

		// Store the previous status so we can check it in the `add_user_to_lists` method, that runs on a later hook.
		self::$previous_statuses[ $user_membership->get_id() ] = $old_status;

		$active_statuses = wc_memberships()->get_user_memberships_instance()->get_active_access_membership_statuses();

		if ( ! in_array( $new_status, $active_statuses ) ) {
			self::remove_user_from_lists( $user_membership );
		}
	}

	/**
	 * Removes user from membership-tied lists associated with a membership plan
	 *
	 * @param \WC_Memberships_User_Membership $user_membership The User Membership object.
	 * @return void
	 */
	public static function remove_user_from_lists( $user_membership ) {
		$lists_to_remove = [];
		$user            = $user_membership->get_user();
		if ( ! $user ) {
			return;
		}
		$user_email = $user->user_email;
		$plan       = $user_membership->get_plan();
		if ( ! $plan instanceof \WC_Memberships_Membership_Plan ) {
			return;
		}

		$rules = $plan->get_content_restriction_rules();
		foreach ( $rules as $rule ) {
			if ( Subscription_Lists::CPT !== $rule->get_content_type_name() ) {
				continue;
			}
			$object_ids = $rule->get_object_ids();
			foreach ( $object_ids as $object_id ) {
				try {
					$subscription_list = new Subscription_List( $object_id );
					$lists_to_remove[] = $subscription_list->get_public_id();
				} catch ( \InvalidArgumentException $e ) {
					continue;
				}
			}
		}

		// Bail if there are no lists we need to remove.
		if ( empty( $lists_to_remove ) ) {
			return;
		}

		/**
		 * Check if the user is already in one of the lists. If they are, store it.
		 *
		 * If they are granted this membership again, we can resubscribe them only to the lists they were in.
		 *
		 * Also, during a subscription renewal, a membership can be momentarily marked as paused, causing the user to be removed from the lists
		 * in which case we want to resubscribe them to the lists they were in.
		 */
		$current_user_lists = \Newspack_Newsletters_Subscription::get_contact_lists( $user_email );
		$existing_lists     = [];

		if ( is_array( $current_user_lists ) ) {
			$existing_lists = array_values( array_intersect( $current_user_lists, $lists_to_remove ) );
		}

		self::update_user_lists_on_deactivation( $user->ID, $user_membership->get_id(), $existing_lists );

		Newspack_Newsletters_Contacts::add_and_remove_lists( $user_email, [], $lists_to_remove, 'Removing user from lists tied to Memberships being marked as inactive' );
		Newspack_Newsletters_Logger::log( 'Reader ' . $user_email . ' removed from the following lists: ' . implode( ', ', $lists_to_remove ) );
	}

	/**
	 * Get list IDs for any lists shown in the post-checkout signup modal.
	 *
	 * @return array Array of list IDs enabled for the post-checkout signup-modal.
	 */
	private static function get_post_checkout_newsletter_signup_lists() {
		$list_ids = [];
		if ( ! method_exists( 'Newspack\Reader_Activation', 'get_settings' ) ) {
			return $list_ids;
		}
		$settings = \Newspack\Reader_Activation::get_settings();
		if ( ! empty( $settings['use_custom_lists'] ) && ! empty( $settings['newsletter_lists'] ) ) {
			foreach ( $settings['newsletter_lists'] as $list ) {
				$list_ids[] = $list['id'];
			}
		}

		return $list_ids;
	}

	/**
	 * Adds user to membership-tied lists when a membership is granted
	 *
	 * @param \WC_Memberships_Membership_Plan $plan the plan that user was granted access to.
	 * @param array                           $args {
	 *     Array of User Membership arguments.
	 *
	 *     @type int $user_id the user ID the membership is assigned to.
	 *     @type int $user_membership_id the user membership ID being saved.
	 *     @type bool $is_update whether this is a post update or a newly created membership.
	 * }
	 * @return void
	 */
	public static function add_user_to_lists( $plan, $args ) {
		// When creating the membership via admin panel, this hook is called once before the membership is actually created.
		if ( ! $plan instanceof \WC_Memberships_Membership_Plan ) {
			return;
		}

		$active_statuses = wc_memberships()->get_user_memberships_instance()->get_active_access_membership_statuses();
		$user_membership = new \WC_Memberships_User_Membership( $args['user_membership_id'] );
		$previous_status = ! empty( self::$previous_statuses[ $user_membership->get_id() ] ) ? self::$previous_statuses[ $user_membership->get_id() ] : false;
		$current_status  = $user_membership->get_status();
		$previous_lists  = get_user_meta( $user_membership->get_user_id(), self::SUBSCRIBED_ON_DEACTIVATION_META_KEY, true );

		// If the membership is no longer active, no need to proceed.
		if ( ! in_array( $current_status, $active_statuses, true ) ) {
			return;
		}

		// Check if we have the previous status stored. If we do and it's an active status
		// it means the membership is being updated from one active status to another active status.
		// In this case, we don't want to add the user to the lists again.
		if ( $previous_status && in_array( $previous_status, $active_statuses, true ) ) {
			Newspack_Newsletters_Logger::log( 'Membership ' . $user_membership->get_id() . ' was already active. No need to subscribe user to lists' );
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

		self::$user_id_in_scope = $user_id;

		$lists_to_add = [];
		$user_email   = $user->user_email;
		$rules        = $plan->get_content_restriction_rules();

		Newspack_Newsletters_Logger::log( 'Membership activated for ' . $user_email );

		foreach ( $rules as $rule ) {
			if ( Subscription_Lists::CPT !== $rule->get_content_type_name() ) {
				continue;
			}
			$object_ids = $rule->get_object_ids();
			foreach ( $object_ids as $object_id ) {
				try {
					$subscription_list = new Subscription_List( $object_id );
					$list_id           = $subscription_list->get_public_id();

					$lists_to_add[] = $list_id;
				} catch ( \InvalidArgumentException $e ) {
					continue;
				}
			}
		}
		if ( empty( $lists_to_add ) ) {
			return;
		}

		// No need to re-add the user to the lists they are already subscribed to.
		$current_user_lists = \Newspack_Newsletters_Subscription::get_contact_lists( $user_email );
		if ( ! is_array( $current_user_lists ) ) {
			$current_user_lists = [];
		}

		// If a list is shown in the post-checkout newsletter signup modal, we only want to add the reader to membership-tied lists if:
		// - The membership is going from `paused` to `active` status (when a prior subscription is renewed).
		// - The reader was already subscribed to the list(s).
		// Otherwise, we want to let readers opt into the lists they want via the post-checkout signup modal.
		$lists_in_post_checkout_signup_modal = self::get_post_checkout_newsletter_signup_lists();
		foreach ( $lists_in_post_checkout_signup_modal as $list_id ) {
			if (
				'paused' !== $previous_status ||
				! $args['is_update'] ||
				empty( $previous_lists[ $args['user_membership_id'] ] ) ||
				! is_array( $previous_lists[ $args['user_membership_id'] ] ) ||
				! in_array( $list_id, $previous_lists[ $args['user_membership_id'] ], true )
			) {
				$lists_to_add = array_values(
					array_diff( $lists_to_add, [ $list_id ] )
				);
			}
		}

		$lists_to_add = array_diff( $lists_to_add, $current_user_lists );
		if ( empty( $lists_to_add ) ) {
			return;
		}

		/**
		 * In case the user was previously removed from the lists, we want to resubscribe them only to the lists they were in.
		 */
		$pre_existing_lists = self::get_user_lists_on_deactivation( $user_id, $user_membership->get_id() );
		if ( ! empty( $pre_existing_lists ) ) {
			$lists_to_add = array_intersect( $lists_to_add, $pre_existing_lists );
		}

		if ( empty( $lists_to_add ) ) {
			return;
		}

		$result = Newspack_Newsletters_Contacts::add_and_remove_lists( $user_email, $lists_to_add, [], 'Adding user to lists tied to Memberships being marked as active' );

		if ( is_wp_error( $result ) ) {
			Newspack_Newsletters_Logger::log( 'An error occured while updating lists for ' . $user_email . ': ' . $result->get_error_message() );
			return;
		}

		Newspack_Newsletters_Logger::log( 'Reader ' . $user_email . ' added to the following lists: ' . implode( ', ', $lists_to_add ) );
	}

	/**
	 * Remove lists that require a membership plan when the membership is cancelled
	 *
	 * @param WC_Memberships_User_Membership $user_membership The User Membership object.
	 * @return void
	 */
	public static function deleted_membership( $user_membership ) {
		self::remove_user_from_lists( $user_membership );
	}

	/**
	 * Stores the lists that the user was subscribed to when their membership was deactivated.
	 *
	 * @param int   $user_id The user ID.
	 * @param int   $membership_id The membership ID.
	 * @param array $lists The lists that the user was subscribed to.
	 * @return void
	 */
	private static function update_user_lists_on_deactivation( $user_id, $membership_id, $lists ) {
		$value = get_user_meta( $user_id, self::SUBSCRIBED_ON_DEACTIVATION_META_KEY, true );
		if ( ! $value ) {
			$value = [];
		}
		$value[ $membership_id ] = $lists;
		update_user_meta( $user_id, self::SUBSCRIBED_ON_DEACTIVATION_META_KEY, $value );
	}

	/**
	 * Gets the lists that the user was subscribed to when their membership was deactivated.
	 *
	 * @param int $user_id The user ID.
	 * @param int $membership_id The membership ID.
	 * @return array
	 */
	private static function get_user_lists_on_deactivation( $user_id, $membership_id ) {
		$value = get_user_meta( $user_id, self::SUBSCRIBED_ON_DEACTIVATION_META_KEY, true );
		if ( ! is_array( $value ) || ! isset( $value[ $membership_id ] ) ) {
			return [];
		}
		return $value[ $membership_id ];
	}

	/**
	 * Determines whether a given list id is associated with a membership plan.
	 *
	 * @param string $list_id The list ID.
	 *
	 * @return bool
	 */
	public static function is_subscription_list_tied_to_plan( $list_id ) {
		if ( ! function_exists( 'wc_memberships_get_membership_plans' ) ) {
			return false;
		}

		$plans = wc_memberships_get_membership_plans();

		if ( empty( $plans ) ) {
			return false;
		}

		foreach ( $plans as $plan ) {
			$rules = $plan->get_content_restriction_rules();

			foreach ( $rules as $rule ) {
				if ( Subscription_Lists::CPT !== $rule->get_content_type_name() ) {
					continue;
				}

				$object_ids = $rule->get_object_ids();
				if ( in_array( $list_id, $object_ids, true ) ) {
					return true;
				}
			}
		}

		return false;
	}
}

Woocommerce_Memberships::init();
