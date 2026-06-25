<?php
/**
 * Newspack Group Subscription invitations.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Newspack Group Subscription Invite class.
 */
class Group_Subscription_Invite {
	/**
	 * The query arg for the group subscription invitation.
	 *
	 * @var string
	 */
	const QUERY_ARG = 'group_invite';

	/**
	 * The subscription meta key for group subscription invite keys.
	 *
	 * @var string
	 */
	const META = 'newspack_group_subscription_invites';

	/**
	 * The email type for group subscription invitations.
	 *
	 * @var string
	 */
	const EMAIL_TYPE = 'group-subscription-invite';

	/**
	 * Query arg for invite result notices.
	 *
	 * @var string
	 */
	const RESULT_QUERY_ARG = 'group_invite_result';

	/**
	 * The query arg used by invite-link URLs.
	 *
	 * @var string
	 */
	const LINK_QUERY_ARG = 'group_invite_link';

	/**
	 * The subscription meta key for invite-link entries.
	 * Stored as: [ $manager_user_id => [ 'key' => string, 'created_at' => int ] ].
	 *
	 * @var string
	 */
	const LINK_META = 'newspack_group_subscription_link_invites';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_filter( 'newspack_email_configs', [ __CLASS__, 'add_email_config' ] );
		add_action( 'template_redirect', [ __CLASS__, 'process_invite_request' ] );
		add_action( 'template_redirect', [ __CLASS__, 'process_link_invite_request' ] );
		add_action( 'template_redirect', [ __CLASS__, 'render_invite_notice' ] );
	}

	/**
	 * Register the group subscription invite email config.
	 *
	 * @param array $configs Email configs.
	 * @return array Modified email configs.
	 */
	public static function add_email_config( $configs ) {
		$configs[ self::EMAIL_TYPE ] = [
			'name'                   => self::EMAIL_TYPE,
			// Reader-revenue category: this is a paid-product email, not
			// an auth/account flow. The chip is derived from category in
			// Emails::apply_config_defaults(), and `recipient` defaults to
			// 'reader' there, so neither needs to be declared here.
			'category'               => 'reader-revenue',
			'label'                  => __( 'Group Subscription Invitation', 'newspack-plugin' ),
			'description'            => __( 'Email sent to invite a reader to join a group subscription.', 'newspack-plugin' ),
			'template'               => dirname( NEWSPACK_PLUGIN_FILE ) . '/includes/templates/reader-activation-emails/group-subscription-invite.php',
			'editor_notice'          => __( 'This email will be sent when a reader is invited to join a group subscription.', 'newspack-plugin' ),
			'trigger_description'    => __( 'Sent to invite a reader to join a group subscription.', 'newspack-plugin' ),
			'available_placeholders' => [
				[
					'label'    => __( 'the site title', 'newspack-plugin' ),
					'template' => '*SITE_TITLE*',
				],
				[
					'label'    => __( 'the site url', 'newspack-plugin' ),
					'template' => '*SITE_URL*',
				],
				[
					'label'    => __( 'the invitation acceptance link', 'newspack-plugin' ),
					'template' => '*INVITE_URL*',
				],
				[
					'label'    => __( 'the sender name', 'newspack-plugin' ),
					'template' => '*SENDER_NAME*',
				],
				[
					'label'    => __( 'the sender email address', 'newspack-plugin' ),
					'template' => '*SENDER_EMAIL*',
				],
				[
					'label'    => __( 'the recipient email address', 'newspack-plugin' ),
					'template' => '*RECIPIENT_EMAIL*',
				],
			],
		];
		return $configs;
	}

	/**
	 * Get the expiration time for a group subscription invitation.
	 * Default is 30 days after the invitation is generated.
	 *
	 * @return int The expiration time.
	 */
	public static function get_expiration_time() {
		return apply_filters( 'newspack_group_subscription_invite_expiration_time', 30 * DAY_IN_SECONDS );
	}

	/**
	 * Get the expiration window as a human-readable label (e.g. "30 days", "1 hour").
	 *
	 * @return string Localized label.
	 */
	public static function get_expiration_label() {
		$seconds = (int) self::get_expiration_time();

		if ( $seconds <= 0 ) {
			$seconds = 1;
		}

		if ( $seconds >= WEEK_IN_SECONDS && 0 === $seconds % WEEK_IN_SECONDS ) {
			$weeks = (int) ( $seconds / WEEK_IN_SECONDS );
			/* translators: %s: number of weeks. */
			return sprintf( _n( '%s week', '%s weeks', $weeks, 'newspack-plugin' ), number_format_i18n( $weeks ) );
		}

		if ( $seconds >= DAY_IN_SECONDS && 0 === $seconds % DAY_IN_SECONDS ) {
			$days = (int) ( $seconds / DAY_IN_SECONDS );
			/* translators: %s: number of days. */
			return sprintf( _n( '%s day', '%s days', $days, 'newspack-plugin' ), number_format_i18n( $days ) );
		}

		if ( $seconds >= HOUR_IN_SECONDS && 0 === $seconds % HOUR_IN_SECONDS ) {
			$hours = (int) ( $seconds / HOUR_IN_SECONDS );
			/* translators: %s: number of hours. */
			return sprintf( _n( '%s hour', '%s hours', $hours, 'newspack-plugin' ), number_format_i18n( $hours ) );
		}

		$minutes = max( 1, (int) floor( $seconds / MINUTE_IN_SECONDS ) );
		/* translators: %s: number of minutes. */
		return sprintf( _n( '%s minute', '%s minutes', $minutes, 'newspack-plugin' ), number_format_i18n( $minutes ) );
	}

	/**
	 * Check if a group subscription invitation has expired.
	 * Expiration timestamps are stored as an array map keyed by invite key.
	 *
	 * @param array $invite The invite data.
	 *
	 * @return bool Whether the invitation has expired.
	 */
	public static function is_invite_expired( $invite ) {
		return $invite['expiration'] < time();
	}

	/**
	 * Get invitations for a given subscription.
	 *
	 * @param \WC_Subscription|int $subscription The subscription object or ID.
	 * @param bool                 $show_expired If true, show expired invitations.
	 *
	 * @return array The invitations.
	 */
	public static function get_invites( $subscription, $show_expired = true ) {
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription );
		if ( ! $subscription ) {
			return [];
		}
		$all_invites = $subscription->get_meta( self::META, true );
		if ( ! is_array( $all_invites ) ) {
			return [];
		}
		if ( ! $show_expired ) {
			foreach ( $all_invites as $key => $invite ) {
				if ( self::is_invite_expired( $invite ) ) {
					unset( $all_invites[ $key ] );
				}
			}
		}
		return $all_invites;
	}

	/**
	 * Get the invite-link entry for a given subscription/manager user pair.
	 *
	 * @param \WC_Subscription|int $subscription The subscription object or ID.
	 * @param int                  $user_id      The manager user ID.
	 *
	 * @return array|null The link-invite entry, or null if missing or subscription invalid.
	 */
	public static function get_link_invite( $subscription, $user_id ) {
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription );
		if ( ! $subscription ) {
			return null;
		}
		$user_id = (int) $user_id;
		$all     = $subscription->get_meta( self::LINK_META, true );
		if ( ! is_array( $all ) ) {
			return null;
		}
		if ( isset( $all[ $user_id ] ) ) {
			return $all[ $user_id ];
		}
		return null;
	}

	/**
	 * Build the public invite-link URL.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param int    $user_id         Manager user ID.
	 * @param string $key             Invite key.
	 *
	 * @return string The invite-link URL.
	 */
	public static function get_link_invite_url( $subscription_id, $user_id, $key ) {
		return add_query_arg(
			[
				'action'       => self::LINK_QUERY_ARG,
				'subscription' => (int) $subscription_id,
				'manager'      => (int) $user_id,
				'key'          => rawurlencode( $key ),
			],
			home_url()
		);
	}

	/**
	 * Generate (or replace) an invite-link for a manager + subscription pair.
	 *
	 * @param \WC_Subscription|int $subscription The subscription object or ID.
	 * @param int                  $user_id      The manager user ID.
	 *
	 * @return array|\WP_Error On success: [ 'url' => string, 'key' => string, 'created_at' => int ].
	 */
	public static function generate_link_invite( $subscription, $user_id ) {
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription );
		if ( ! $subscription || ! Group_Subscription::is_group_subscription( $subscription ) ) {
			return new \WP_Error(
				'newspack_group_subscription_link_invite_invalid_subscription',
				__( 'Invalid subscription.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}
		$user_id = (int) $user_id;
		if ( ! Group_Subscription::user_is_manager( $user_id, $subscription ) ) {
			return new \WP_Error(
				'newspack_group_subscription_link_invite_not_manager',
				__( 'You do not have permission to manage this group subscription.', 'newspack-plugin' ),
				[ 'status' => 403 ]
			);
		}

		$all = $subscription->get_meta( self::LINK_META, true );
		if ( ! is_array( $all ) ) {
			$all = [];
		}

		$now   = time();
		$entry = [
			'key'        => wp_generate_password( 32, false ),
			'created_at' => $now,
		];
		$all[ $user_id ] = $entry;

		$subscription->update_meta_data( self::LINK_META, $all );
		$subscription->save();

		return array_merge(
			$entry,
			[ 'url' => self::get_link_invite_url( $subscription->get_id(), $user_id, $entry['key'] ) ]
		);
	}

	/**
	 * Delete an invite link for a given subscription/manager user pair.
	 *
	 * @param \WC_Subscription|int $subscription The subscription object or ID.
	 * @param int                  $user_id      The manager user ID.
	 *
	 * @return true|\WP_Error True if deleted, or WP_Error.
	 */
	public static function delete_link_invite( $subscription, $user_id ) {
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription );
		if ( ! $subscription || ! Group_Subscription::is_group_subscription( $subscription ) ) {
			return new \WP_Error(
				'newspack_group_subscription_link_invite_invalid_subscription',
				__( 'Invalid subscription.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}
		$user_id = (int) $user_id;
		if ( ! Group_Subscription::user_is_manager( $user_id, $subscription ) ) {
			return new \WP_Error(
				'newspack_group_subscription_link_invite_not_manager',
				__( 'You do not have permission to manage this group subscription.', 'newspack-plugin' ),
				[ 'status' => 403 ]
			);
		}

		$all = $subscription->get_meta( self::LINK_META, true );
		if ( ! is_array( $all ) || ! isset( $all[ $user_id ] ) ) {
			return true;
		}
		unset( $all[ $user_id ] );
		$subscription->update_meta_data( self::LINK_META, $all );
		$subscription->save();
		return true;
	}

	/**
	 * Validate an invite-link at click-time.
	 *
	 * @param \WC_Subscription|int $subscription Subscription object or ID.
	 * @param int                  $user_id      Manager user ID.
	 * @param string               $key          Invite key from the URL.
	 *
	 * @return true|\WP_Error True if valid; otherwise an error code.
	 */
	public static function validate_link_invite( $subscription, $user_id, $key ) {
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription );
		if ( ! $subscription || ! Group_Subscription::is_group_subscription( $subscription ) ) {
			return new \WP_Error(
				'newspack_group_subscription_link_invite_invalid_subscription',
				__( 'Invalid subscription.', 'newspack-plugin' )
			);
		}
		if ( ! $subscription->has_status( WooCommerce_Connection::ACTIVE_SUBSCRIPTION_STATUSES ) ) {
			return new \WP_Error(
				'newspack_group_subscription_link_invite_invalid_subscription',
				__( 'Subscription is not active.', 'newspack-plugin' )
			);
		}
		$user_id = (int) $user_id;
		if ( ! Group_Subscription::user_is_manager( $user_id, $subscription ) ) {
			return new \WP_Error(
				'newspack_group_subscription_link_invite_not_manager',
				__( 'The link manager is no longer a manager of this subscription.', 'newspack-plugin' )
			);
		}
		$entry = self::get_link_invite( $subscription, $user_id );
		if ( ! $entry || empty( $entry['key'] ) || ! hash_equals( (string) $entry['key'], (string) $key ) ) {
			return new \WP_Error(
				'newspack_group_subscription_link_invite_not_found',
				__( 'Invite link not found.', 'newspack-plugin' )
			);
		}
		return true;
	}

	/**
	 * Generate a group subscription invite key.
	 *
	 * @param \WC_Subscription|int $subscription The subscription object or ID.
	 * @param string               $email The email address receiving the invitation.
	 *
	 * @return array|\WP_Error The invite data, or a WP_Error if the key cannot be generated.
	 */
	public static function generate_invite( $subscription, $email ) {
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription );
		if ( ! $subscription || ! Group_Subscription::is_group_subscription( $subscription ) ) {
			return new \WP_Error( 'newspack_group_subscription_invite_invalid_subscription', __( 'Invalid subscription.', 'newspack-plugin' ) );
		}
		if ( ! $subscription->has_status( WooCommerce_Connection::ACTIVE_SUBSCRIPTION_STATUSES ) ) {
			return new \WP_Error(
				'newspack_group_subscription_invite_inactive',
				sprintf(
					/* translators: %s: lowercase singular group label (e.g. "group", "team"). */
					__( 'This %s is no longer active.', 'newspack-plugin' ),
					Group_Subscription::get_label_lower( 'singular' )
				)
			);
		}
		if ( ! $email ) {
			return new \WP_Error( 'newspack_group_subscription_invite_invalid_email', __( 'Invalid email address.', 'newspack-plugin' ) );
		}
		$existing_user = get_user_by( 'email', $email );
		if ( $existing_user && ! Reader_Activation::is_user_reader( $existing_user ) ) {
			return new \WP_Error( 'newspack_group_subscription_invite_non_reader', __( 'Not a valid reader account.', 'newspack-plugin' ) );
		}
		if ( $existing_user && in_array( (int) $existing_user->ID, array_map( 'absint', Group_Subscription::get_members( $subscription ) ), true ) ) {
			return new \WP_Error( 'newspack_group_subscription_invite_existing_user', __( 'User is already a member of this group subscription.', 'newspack-plugin' ) );
		}

		// Delete any invites for the given email address. There should only be one invitation per email address.
		$all_invites = self::get_invites( $subscription );
		foreach ( $all_invites as $key => $invite ) {
			if ( $invite['email'] === $email ) {
				unset( $all_invites[ $key ] );
			}
		}

		// The number of pending invites + existing members should not exceed the subscription member limit.
		$pending_invites_count = count(
			array_filter(
				array_values( $all_invites ),
				function( $invite_data ) {
					return ! self::is_invite_expired( $invite_data );
				}
			)
		);
		$subscription_settings = Group_Subscription_Settings::get_subscription_settings( $subscription );
		if ( $subscription_settings['limit'] > 0 ) {
			if ( $pending_invites_count + count( Group_Subscription::get_members( $subscription ) ) >= $subscription_settings['limit'] ) {
				return new \WP_Error( 'newspack_group_subscription_invite_limit_reached', __( 'You have reached the group member limit for this subscription. Please remove some members or cancel pending invitations before inviting more group members.', 'newspack-plugin' ) );
			}
		}

		// Add the new invite.
		$invite_key = wp_generate_password( 32, false );
		$new_invite = [
			'added_by'   => get_current_user_id(),
			'email'      => $email,
			'expiration' => time() + self::get_expiration_time(),
		];
		$all_invites[ $invite_key ] = $new_invite;

		$subscription->update_meta_data( self::META, $all_invites );
		$subscription->save();

		self::send_invite_email( $subscription->get_id(), $invite_key, $email );

		return $new_invite;
	}

	/**
	 * Send an invitation email.
	 *
	 * @param int    $subscription_id The subscription ID.
	 * @param string $key The invite key.
	 * @param string $email The invited email address.
	 *
	 * @return bool Whether the email was sent.
	 */
	public static function send_invite_email( $subscription_id, $key, $email ) {
		$url          = self::get_invite_url( $subscription_id, $key, $email );
		$invite       = self::get_invite_by_key( $subscription_id, $key );
		$sender_email = '';
		$sender_name  = '';
		if ( $invite && ! empty( $invite['added_by'] ) ) {
			$sender = get_user_by( 'id', $invite['added_by'] );
			if ( $sender ) {
				$sender_email = $sender->user_email;
				$sender_name  = $sender->display_name;
			}
		}
		return Emails::send_email(
			self::EMAIL_TYPE,
			$email,
			[
				[
					'template' => '*INVITE_URL*',
					'value'    => $url,
				],
				[
					'template' => '*SENDER_NAME*',
					'value'    => $sender_name,
				],
				[
					'template' => '*SENDER_EMAIL*',
					'value'    => $sender_email,
				],
				[
					'template' => '*RECIPIENT_EMAIL*',
					'value'    => $email,
				],
			]
		);
	}

	/**
	 * Accept a group subscription invitation.
	 * Validates the invite, adds the user to the group, and deletes the invite.
	 *
	 * @param \WC_Subscription|int $subscription The subscription object or ID.
	 * @param string               $key The invite key.
	 * @param string               $email The email address of the invitee.
	 *
	 * @return true|\WP_Error True on success, or a WP_Error on failure.
	 */
	public static function accept_invite( $subscription, $key, $email ) {
		$subscription_obj = WooCommerce_Subscriptions::sanitize_subscription( $subscription );
		if ( ! $subscription_obj || ! $subscription_obj->has_status( WooCommerce_Connection::ACTIVE_SUBSCRIPTION_STATUSES ) ) {
			return new \WP_Error(
				'newspack_group_subscription_invite_inactive',
				sprintf(
					/* translators: %s: lowercase singular group label (e.g. "group", "team"). */
					__( 'This %s is no longer active.', 'newspack-plugin' ),
					Group_Subscription::get_label_lower( 'singular' )
				)
			);
		}
		$invite = self::get_invite_by_key( $subscription, $key );
		if ( ! $invite || $invite['email'] !== $email ) {
			// No need to display an error if the user is already a member: just give a success message.
			$current_user_id = get_current_user_id();
			if (
				Group_Subscription::user_is_manager( $current_user_id, $subscription )
				|| Group_Subscription::user_is_member( $current_user_id, $subscription )
			) {
				return true;
			}
			return new \WP_Error( 'newspack_group_subscription_invite_not_found', __( 'Invalid or expired invitation.', 'newspack-plugin' ) );
		}
		if ( self::is_invite_expired( $invite ) ) {
			return new \WP_Error( 'newspack_group_subscription_invite_expired', __( 'This invitation has expired.', 'newspack-plugin' ) );
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return new \WP_Error( 'newspack_group_subscription_invite_no_user', __( 'No user found for this email address.', 'newspack-plugin' ) );
		}

		$result = Group_Subscription::update_members( $subscription, [ $user->ID ] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// update_members() returns an empty members_added both when the user could not be added
		// (e.g. a non-reader account) AND when they are already a member (it skips the duplicate).
		// Only the genuine non-add is a failure: leave the invite intact so it can be retried.
		// An already-member is a fulfilled invite, so fall through to cancel it (it would otherwise
		// keep counting toward the member limit).
		// user_is_member() returns bool here (the invite always targets a group subscription, so the
		// null "not a group subscription" case can't occur); a falsy result means "not a member".
		if ( empty( $result['members_added'][ $user->ID ] ) && ! Group_Subscription::user_is_member( $user->ID, $subscription ) ) {
			return new \WP_Error(
				'newspack_group_subscription_invite_not_added',
				__( 'Could not add this user to the group.', 'newspack-plugin' )
			);
		}

		self::cancel_invite( $subscription, $email );
		return true;
	}

	/**
	 * Get the invite URL for a group subscription invitation.
	 *
	 * @param int    $subscription_id The subscription ID.
	 * @param string $key The invite key.
	 * @param string $email The invited email address.
	 *
	 * @return string The invite URL.
	 */
	public static function get_invite_url( $subscription_id, $key, $email ) {
		return add_query_arg(
			[
				'action'       => self::QUERY_ARG,
				'key'          => $key,
				'email'        => rawurlencode( $email ),
				'subscription' => $subscription_id,
			],
			home_url()
		);
	}

	/**
	 * Get an invite by its key.
	 *
	 * @param \WC_Subscription|int $subscription The subscription object or ID.
	 * @param string               $key The invite key.
	 *
	 * @return array|null The invite data, or null if not found.
	 */
	public static function get_invite_by_key( $subscription, $key ) {
		$invites = self::get_invites( $subscription );
		return $invites[ $key ] ?? null;
	}

	/**
	 * Process an invite link request.
	 * Handles the ?action=group_invite URL.
	 */
	public static function process_invite_request() {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return;
		}
		if ( ! isset( $_GET['action'] ) || self::QUERY_ARG !== $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$key             = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$email           = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$subscription_id = isset( $_GET['subscription'] ) ? absint( $_GET['subscription'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $key || ! $email || ! $subscription_id ) {
			self::redirect_with_result( 'error_invalid_link' );
			return;
		}

		$myaccount_url = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'edit-account' ) : home_url();

		// Case 1: User is logged in.
		$current_user = wp_get_current_user();
		if ( $current_user->ID ) {
			if ( $current_user->user_email !== $email ) {
				self::redirect_with_result( 'error_email_mismatch' );
				return;
			}
			$result = self::accept_invite( $subscription_id, $key, $email );
			if ( is_wp_error( $result ) ) {
				do_action(
					'newspack_log',
					'newspack_group_subscription_invite_failed',
					$result->get_error_message(),
					[
						'type'       => 'error',
						'data'       => [
							'subscription_id' => $subscription_id,
							'member_id'       => $current_user->ID,
						],
						'user_email' => $email,
					]
				);
				self::redirect_with_result( 'error_invite_invalid' );
				return;
			}
			$success_url = function_exists( 'wc_get_endpoint_url' )
					? wc_get_endpoint_url( 'view-subscription', $subscription_id, $myaccount_url )
					: $myaccount_url;
			self::redirect_with_result( 'success', $success_url );
			return;
		}

		// Case 2: User is not logged in but has an existing account — redirect to login.
		$existing_user = get_user_by( 'email', $email );
		if ( $existing_user ) {
			self::redirect_with_result(
				'login_needed',
				add_query_arg(
					[

						/*
						 * rawurlencode( $link_url ) is required: WP's add_query_arg() does NOT
						 * encode NEW arg values (only existing query args via urlencode_deep).
						 * Without pre-encoding, the link URL's inner `&s=…&m=…&k=…` would leak
						 * into the outer query string. PHP's $_GET parser decodes URL-encoded
						 * values once on receipt, so downstream consumers (e.g. Reader Activation
						 * reading $_GET['redirect']) see the exact original $link_url.
						 */
						'redirect' => rawurlencode( self::get_invite_url( $subscription_id, $key, $email ) ),
					],
					$myaccount_url
				)
			);
			return;
		}

		// Case 3: New user — auto-create account, verify email, and accept.
		$user_id = Reader_Activation::register_reader( $email, false );
		if ( is_wp_error( $user_id ) || ! $user_id ) {
			do_action(
				'newspack_log',
				'newspack_group_subscription_invite_registration_failed',
				$user_id ? $user_id->get_error_message() : __( 'New user registration failed.', 'newspack-plugin' ),
				[
					'type'       => 'error',
					'data'       => [
						'subscription_id' => $subscription_id,
					],
					'user_email' => $email,
				]
			);
			self::redirect_with_result( 'error_registration_failed' );
			return;
		}
		Reader_Activation::set_reader_verified( $user_id );
		Reader_Activation::set_current_reader( $user_id );

		$result = self::accept_invite( $subscription_id, $key, $email );
		if ( is_wp_error( $result ) ) {
			do_action(
				'newspack_log',
				'newspack_group_subscription_invite_failed',
				$result->get_error_message(),
				[
					'type'       => 'error',
					'data'       => [
						'subscription_id' => $subscription_id,
						'member_id'       => $user_id,
					],
					'user_email' => $email,
				]
			);
			self::redirect_with_result( 'error_invite_invalid' );
			return;
		}
		$success_url = function_exists( 'wc_get_endpoint_url' )
				? wc_get_endpoint_url( 'view-subscription', $subscription_id, $myaccount_url )
				: $myaccount_url;
		self::redirect_with_result( 'success', $success_url );
	}

	/**
	 * Process an invite-link click.
	 * Handles the ?action=group_invite_link URL.
	 */
	public static function process_link_invite_request() {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return;
		}
		if ( ! isset( $_GET['action'] ) || self::LINK_QUERY_ARG !== $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$subscription_id = isset( $_GET['subscription'] ) ? absint( $_GET['subscription'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id         = isset( $_GET['manager'] ) ? absint( $_GET['manager'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key             = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription_id );

		// Compute "where do we send them on errors" for both auth states.
		$current_user      = wp_get_current_user();
		$is_logged_in      = (bool) $current_user->ID;
		$myaccount_url     = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'edit-account' ) : home_url();
		$error_target_url  = $is_logged_in ? $myaccount_url : home_url();

		// Validate the link.
		$validation = self::validate_link_invite( $subscription, $user_id, $key );
		if ( is_wp_error( $validation ) ) {
			do_action(
				'newspack_log',
				'newspack_group_subscription_invite_link_invalid',
				$validation->get_error_message(),
				[
					'type' => 'error',
					'data' => [
						'subscription_id' => $subscription_id,
						'manager_id'      => $user_id,
						'member_id'       => $current_user->ID,
					],
				]
			);
			self::redirect_with_result( 'link_invalid', $error_target_url );
			return;
		}

		// Not logged in → bounce to My Account with redirect=back-to-link, banner via 'login_needed'.
		if ( ! $is_logged_in ) {
			$link_url = self::get_link_invite_url( $subscription_id, $user_id, $key );
			self::redirect_with_result( 'login_needed', add_query_arg( [ 'redirect' => rawurlencode( $link_url ) ], $myaccount_url ) );
			return;
		}

		// User is already in the group? Just send them to the subscription view.
		if (
			Group_Subscription::user_is_manager( $current_user->ID, $subscription )
			|| Group_Subscription::user_is_member( $current_user->ID, $subscription )
		) {
			$success_url = function_exists( 'wc_get_endpoint_url' )
					? wc_get_endpoint_url( 'view-subscription', $subscription->get_id(), $myaccount_url )
					: $myaccount_url;
			self::redirect_with_result( 'success', $success_url );
			return;
		}

		// Member-limit check.
		$settings             = Group_Subscription_Settings::get_subscription_settings( $subscription );
		$member_count         = count( Group_Subscription::get_members( $subscription ) );
		$pending_invite_count = count( self::get_invites( $subscription, false ) );

		if ( $settings['limit'] > 0 && ( $member_count + $pending_invite_count ) >= $settings['limit'] ) {
			self::redirect_with_result( 'link_full', $error_target_url );
			return;
		}

		// Attempt to add the current user as a member.
		$result = Group_Subscription::update_members( $subscription, [ $current_user->ID ] );
		if ( is_wp_error( $result ) || empty( $result['members_added'][ $current_user->ID ] ) ) {
			// update_members() returns either a WP_Error (subscription invalid, limit reached) or
			// an array that can legitimately have an empty members_added (e.g. the current user is
			// not a Reader Activation reader, so the per-member loop skipped them). Only WP_Error
			// has get_error_message(); the array path needs its own message.
			$error_message = is_wp_error( $result )
				? $result->get_error_message()
				: __( 'Could not add the current user to the group.', 'newspack-plugin' );
			do_action(
				'newspack_log',
				'newspack_group_subscription_invite_link_failed',
				$error_message,
				[
					'type' => 'error',
					'data' => [
						'subscription_id' => $subscription_id,
						'manager_id'      => $user_id,
						'member_id'       => $current_user->ID,
					],
				]
			);
			self::redirect_with_result( 'link_failed', $error_target_url );
			return;
		}

		// Success → subscription view URL.
		$success_url = function_exists( 'wc_get_endpoint_url' )
		? wc_get_endpoint_url( 'view-subscription', $subscription->get_id(), $myaccount_url )
		: $myaccount_url;
		self::redirect_with_result( 'success', $success_url );
	}

	/**
	 * Render invite result notice.
	 */
	public static function render_invite_notice() {
		$result = isset( $_GET[ self::RESULT_QUERY_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::RESULT_QUERY_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $result ) {
			return;
		}

		$messages = [
			'link_invalid'              => [
				'message' => __( 'This link is no longer valid. Please contact the group manager.', 'newspack-plugin' ),
				'type'    => 'error',
			],
			'link_full'                 => [
				'message' => __( 'This group already has the maximum number of members. Please contact the group manager.', 'newspack-plugin' ),
				'type'    => 'error',
			],
			'link_failed'               => [
				'message' => __( "We couldn't add you to the group. Please contact the group manager.", 'newspack-plugin' ),
				'type'    => 'error',
			],
			'login_needed'              => [
				'message' => __( 'Please log in or register an account to join the group.', 'newspack-plugin' ),
				'type'    => 'notice',
			],
			'error_invalid_link'        => [
				'message' => __( 'Invalid invitation link.', 'newspack-plugin' ),
				'type'    => 'error',
			],
			'error_email_mismatch'      => [
				'message' => __( 'This invitation is for a different email address.', 'newspack-plugin' ),
				'type'    => 'error',
			],
			'error_invite_invalid'      => [
				'message' => __( 'Invalid or expired invitation.', 'newspack-plugin' ),
				'type'    => 'error',
			],
			'error_registration_failed' => [
				'message' => __( 'Could not create your account. Please try again.', 'newspack-plugin' ),
				'type'    => 'error',
			],
		];

		if ( 'success' === $result ) {
			$message = __( 'You have successfully joined the group!', 'newspack-plugin' );
			$type    = 'success';
		} else {
			$message = ! empty( $messages[ $result ]['message'] ) ? $messages[ $result ]['message'] : __( 'There was a problem with your invitation.', 'newspack-plugin' );
			$type = ! empty( $messages[ $result ]['type'] ) ? $messages[ $result ]['type'] : 'error';
		}

		Newspack_UI::add_notice( $message, $type );
	}

	/**
	 * Redirect to a target URL with a result query parameter.
	 *
	 * @param string      $status     A discrete result code (e.g. 'success', 'login_needed',
	 *                                'error_email_mismatch', 'link_invalid'). The receiving
	 *                                render_invite_notice() maps the code to a localized message.
	 * @param string|null $target_url Optional redirect base. Defaults to My Account or home_url().
	 */
	private static function redirect_with_result( $status, $target_url = null ) {
		$args = [ self::RESULT_QUERY_ARG => $status ];
		if ( null === $target_url ) {
			$target_url = is_user_logged_in() && function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'edit-account' ) : home_url();
		}
		wp_safe_redirect( add_query_arg( $args, $target_url ) );
		exit;
	}

	/**
	 * Cancel a pending invite for a given subscription and email address.
	 *
	 * @param \WC_Subscription|int $subscription The subscription object or ID.
	 * @param string               $email The email address receiving the invitation.
	 *
	 * @return true|\WP_Error Whether the invite was cancelled, or a WP_Error if the invite cannot be cancelled.
	 */
	public static function cancel_invite( $subscription, $email ) {
		$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription );
		if ( ! $subscription || ! Group_Subscription::is_group_subscription( $subscription ) ) {
			return new \WP_Error( 'newspack_group_subscription_invite_invalid_subscription', __( 'Invalid subscription.', 'newspack-plugin' ) );
		}
		if ( ! $email ) {
			return new \WP_Error( 'newspack_group_subscription_invite_invalid_email', __( 'Invalid email address.', 'newspack-plugin' ) );
		}
		$all_invites = self::get_invites( $subscription );
		foreach ( $all_invites as $key => $invite ) {
			if ( $invite['email'] === $email ) {
				unset( $all_invites[ $key ] );
			}
		}
		$subscription->update_meta_data( self::META, $all_invites );
		$subscription->save();
		return true;
	}
}
Group_Subscription_Invite::init();
