<?php
/**
 * Reader Activation Data Library Class.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Memberships;

require_once NEWSPACK_ABSPATH . 'includes/reader-activation/cli/class-sync-reader-data-cli.php';

defined( 'ABSPATH' ) || exit;

/**
 * Reader Data Class.
 */
final class Reader_Data {

	// Maximum number of items per user.
	const MAX_ITEMS = 100;

	/**
	 * Reader activity to push.
	 *
	 * @var array
	 */
	private static $reader_activity = [];

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( 'wp', [ __CLASS__, 'setup_reader_activity' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'config_script' ] );
		add_action( 'init', [ __CLASS__, 'register_data_event_handlers' ] );
		add_filter( 'newspack_session_hydration_response', [ __CLASS__, 'add_reader_data_to_hydration' ], 10, 2 );
	}

	/**
	 * Add reader data items to the session hydration response.
	 *
	 * @param array $data    Hydration response data.
	 * @param int   $user_id The authenticated user's ID.
	 *
	 * @return array Filtered response data.
	 */
	public static function add_reader_data_to_hydration( $data, $user_id ) {
		$data['reader_data_items'] = self::get_data( $user_id );
		return $data;
	}

	/**
	 * Enumerate read-only keys.
	 *
	 * Server is the source of truth for these keys, and they
	 * shouldn't be written to or deleted by the client.
	 *
	 * This list is surfaced to the client as
	 * `newspack_reader_data.read_only_keys` to allow browser-side
	 * validation and more graceful error handling.
	 *
	 * Implemented in a filter hook to permit plugins to alter the list.
	 *
	 * @return string[] Names of read-only keys.
	 */
	public static function get_read_only_keys() {
		$keys = [
			'active_memberships',
			'active_subscriptions',
			'is_former_donor',
			'newsletter_subscribed_lists',
		];

		// is_donor is only read-only when the platform has a secure server-side
		// mechanism to manage donor status. Currently only WooCommerce has this
		// via the donation_new data event. Non-Woo platforms (NRH, other) rely
		// on client-side writes from the donor landing page.
		//
		// Note: when is_donor is NOT read-only, any authenticated reader can
		// set it via the REST API. This is an intentional trade-off — is_donor
		// is used for segmentation and analytics, not access control. Consumers
		// that need to distinguish server-verified from client-asserted donor
		// status should check Donations::has_server_side_donor_tracking().
		if ( Donations::has_server_side_donor_tracking() ) {
			$keys[] = 'is_donor';
		}

		/**
		 * Filters the list of read-only reader data keys.
		 *
		 * This list is used for both client-side configuration (via wp_localize_script)
		 * and server-side REST API enforcement. Note that filter callbacks relying on
		 * page-context conditionals (is_page, get_the_ID, etc.) will only affect the
		 * client-side path.
		 *
		 * @param string[] $keys Names of read-only keys.
		 */
		return apply_filters( 'newspack_reader_data_read_only_keys', $keys );
	}

	/**
	 * Register all data event handlers.
	 */
	public static function register_data_event_handlers() {
		/* Update reader data items on data event dispatches */
		Data_Events::register_handler( [ __CLASS__, 'update_newsletter_subscribed_lists' ], 'newsletter_subscribed' );
		Data_Events::register_handler( [ __CLASS__, 'update_newsletter_subscribed_lists' ], 'newsletter_updated' );
		Data_Events::register_handler( [ __CLASS__, 'set_is_donor' ], 'donation_new' );
		Data_Events::register_handler( [ __CLASS__, 'set_is_former_donor' ], 'donation_subscription_cancelled' );
		Data_Events::register_handler( [ __CLASS__, 'update_active_subscriptions' ], 'product_subscription_changed' );
		Data_Events::register_handler( [ __CLASS__, 'update_active_memberships' ], 'membership_status_active' );
		Data_Events::register_handler( [ __CLASS__, 'update_active_memberships' ], 'membership_status_inactive' );
		Data_Events::register_handler( [ __CLASS__, 'check_newsletter_subscription' ], 'reader_logged_in' );
		Data_Events::register_handler( [ __CLASS__, 'check_product_subscriptions' ], 'reader_logged_in' );
		Data_Events::register_handler( [ __CLASS__, 'check_memberships' ], 'reader_logged_in' );
	}

	/**
	 * Add config to the data script.
	 */
	public static function config_script() {
		/**
		 * Filters the localStorage store item prefix.
		 *
		 * @param string $store_prefix Prefix.
		 */
		$store_prefix = apply_filters(
			'newspack_reader_data_store_prefix',
			sprintf( 'np_reader_%d_', \get_current_blog_id() )
		);

		/**
		 * Allows for "temporary" reader data for things like previews.
		 * If true, the store will use sessionStorage instead of localStorage.
		 */
		$is_temporary = apply_filters( 'newspack_reader_data_store_is_temp_session', false );

		$config = [
			'store_prefix'    => $store_prefix,
			'is_temporary'    => $is_temporary,
			'reader_activity' => self::$reader_activity,
			'read_only_keys'  => self::get_read_only_keys(),
			'api_url'         => \get_rest_url( null, NEWSPACK_API_NAMESPACE . '/reader-data' ),
			'session_url'     => \get_rest_url( null, NEWSPACK_API_NAMESPACE . '/reader/session' ),
		];

		if ( \is_user_logged_in() ) {
			$config['nonce'] = \wp_create_nonce( 'wp_rest' );
			$config['items'] = self::get_data( \get_current_user_id() );
		}

		wp_localize_script( Reader_Activation::SCRIPT_HANDLE, 'newspack_reader_data', $config );
	}

	/**
	 * Register routes.
	 */
	public static function register_routes() {
		\register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/reader-data',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'api_update_item' ],
				'permission_callback' => [ __CLASS__, 'permission_callback' ],
				'args'                => [
					'key'   => [
						'type' => 'string',
					],
					'value' => [
						'type' => 'string',
					],
				],
			]
		);
		\register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/reader-data',
			[
				'methods'             => 'DELETE',
				'callback'            => [ __CLASS__, 'api_delete_item' ],
				'permission_callback' => [ __CLASS__, 'permission_callback' ],
				'args'                => [
					'key' => [
						'type' => 'string',
					],
				],
			]
		);
	}

	/**
	 * Whether the current user can access the API.
	 */
	public static function permission_callback() {
		return \is_user_logged_in();
	}

	/**
	 * Get the user meta key.
	 *
	 * @param string $key Key.
	 */
	public static function get_meta_key_name( $key ) {
		return 'newspack_reader_data_item_' . $key;
	}

	/**
	 * Get reader data.
	 *
	 * @param string $user_id User ID.
	 * @param string $key     Optional key to return.
	 *
	 * @return mixed Key data if provided, array of data or false if key not found.
	 */
	public static function get_data( $user_id, $key = '' ) {
		$user_keys = \get_user_meta( $user_id, 'newspack_reader_data_keys', true );
		if ( ! $user_keys ) {
			return ! empty( $key ) ? false : [];
		}

		if ( $key ) {
			if ( ! in_array( $key, $user_keys, true ) ) {
				return false;
			}
			return \get_user_meta( $user_id, self::get_meta_key_name( $key ), true );
		}

		$data = [];
		foreach ( $user_keys as $key ) {
			$data[ $key ] = \get_user_meta( $user_id, self::get_meta_key_name( $key ), true );
		}

		return $data;
	}

	/**
	 * Update reader data item.
	 *
	 * @param string $user_id User ID.
	 * @param string $key     Key.
	 * @param string $value   Value.
	 *
	 * @return true|WP_Error True on success, error object on failure.
	 */
	public static function update_item( $user_id, $key, $value ) {
		$user_keys = \get_user_meta( $user_id, 'newspack_reader_data_keys', true );
		if ( ! $user_keys ) {
			$user_keys = [];
		}

		if ( ! is_string( $value ) ) {
			$value = wp_json_encode( $value );
		}

		$value = sanitize_text_field( $value );
		if ( ! $value ) {
			return new \WP_Error( 'invalid_value', __( 'Invalid value.', 'newspack' ), [ 'status' => 400 ] );
		}

		/**
		 * Filter the maximum number of items per user.
		 *
		 * @param int    $max_items Maximum number of items.
		 * @param int    $user_id   User ID.
		 * @param string $key       Key.
		 * @param string $value     Value.
		 */
		$max_items = apply_filters( 'newspack_reader_data_max_items', self::MAX_ITEMS, $user_id, $key, $value );
		if ( count( $user_keys ) >= self::MAX_ITEMS ) {
			return new \WP_Error( 'too_many_items', __( 'Too many items.', 'newspack' ), [ 'status' => 400 ] );
		}

		if ( ! in_array( $key, $user_keys, true ) ) {
			$user_keys[] = $key;
			\update_user_meta( $user_id, 'newspack_reader_data_keys', $user_keys );
		}

		\update_user_meta( $user_id, self::get_meta_key_name( $key ), $value );

		/**
		 * Fires after a reader data item is updated.
		 *
		 * @param int    $user_id User ID.
		 * @param string $key     Key.
		 * @param string $value   Value.
		 */
		do_action( 'newspack_reader_data_updated', $user_id, $key, $value );
		return true;
	}

	/**
	 * Delete user data item.
	 *
	 * @param string $user_id User ID.
	 * @param string $key     Key.
	 */
	private static function delete_item( $user_id, $key ) {
		$user_keys = \get_user_meta( $user_id, 'newspack_reader_data_keys', true );
		if ( ! $user_keys ) {
			$user_keys = [];
		}
		if ( in_array( $key, $user_keys, true ) ) {
			$user_keys = array_diff( $user_keys, [ $key ] );
			\update_user_meta( $user_id, 'newspack_reader_data_keys', $user_keys );
		}
		\delete_user_meta( $user_id, self::get_meta_key_name( $key ) );

		/**
		 * Fires after a reader data item is deleted.
		 *
		 * @param int         $user_id User ID.
		 * @param string      $key     Key.
		 * @param string|null $value   Value. Null when the item is deleted.
		 */
		do_action( 'newspack_reader_data_updated', $user_id, $key, null );
		return true;
	}

	/**
	 * API callback to update an item.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public static function api_update_item( $request ) {
		$key   = $request->get_param( 'key' );
		$value = $request->get_param( 'value' );
		if ( ! $key || ! $value ) {
			return new \WP_Error( 'invalid_params', __( 'Invalid parameters.', 'newspack' ), [ 'status' => 400 ] );
		}
		if ( in_array( $key, self::get_read_only_keys(), true ) ) {
			return new \WP_Error( 'read_only_key', __( 'This key is read-only.', 'newspack' ), [ 'status' => 403 ] );
		}
		// Value must be a valid stringified JSON.
		if ( null === json_decode( $value ) ) {
			return new \WP_Error( 'invalid_value', __( 'Invalid value.', 'newspack' ), [ 'status' => 400 ] );
		}
		$res = self::update_item( \get_current_user_id(), $key, $value );
		if ( \is_wp_error( $res ) ) {
			return $res;
		}
		return new \WP_REST_Response( [ 'success' => true ] );
	}

	/**
	 * API callback to delete an item.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public static function api_delete_item( $request ) {
		$key = $request->get_param( 'key' );
		if ( ! $key ) {
			return new \WP_Error( 'invalid_params', __( 'Invalid parameters.', 'newspack' ), [ 'status' => 400 ] );
		}
		if ( in_array( $key, self::get_read_only_keys(), true ) ) {
			return new \WP_Error( 'read_only_key', __( 'This key is read-only.', 'newspack' ), [ 'status' => 403 ] );
		}
		self::delete_item( \get_current_user_id(), $key );
		return new \WP_REST_Response( [ 'success' => true ] );
	}

	/**
	 * Set the user as a newsletter subscriber.
	 *
	 * @param int   $timestamp Timestamp.
	 * @param array $data      Data.
	 */
	public static function update_newsletter_subscribed_lists( $timestamp, $data ) {
		if ( ! isset( $data['user_id'] ) ) {
			return;
		}
		if ( ! empty( $data['lists'] ) ) {
			self::update_item( $data['user_id'], 'is_newsletter_subscriber', true );
			self::update_item( $data['user_id'], 'newsletter_subscribed_lists', wp_json_encode( $data['lists'] ) );
		}
		if ( ! empty( $data['lists_added'] ) ) {
			$newsletter_subscribed_lists = self::get_data( $data['user_id'], 'newsletter_subscribed_lists' );
			if ( ! empty( $newsletter_subscribed_lists ) && gettype( $newsletter_subscribed_lists ) === 'string' ) {
				$newsletter_subscribed_lists = json_decode( $newsletter_subscribed_lists );
			}
			if ( ! empty( $newsletter_subscribed_lists ) && is_array( $newsletter_subscribed_lists ) ) {
				$newsletter_subscribed_lists = array_merge( $newsletter_subscribed_lists, $data['lists_added'] );
			} else {
				$newsletter_subscribed_lists = $data['lists_added'];
			}
			self::update_item( $data['user_id'], 'is_newsletter_subscriber', true );
			self::update_item( $data['user_id'], 'newsletter_subscribed_lists', wp_json_encode( $newsletter_subscribed_lists ) );
		}
		if ( ! empty( $data['lists_removed'] ) ) {
			$newsletter_subscribed_lists = self::get_data( $data['user_id'], 'newsletter_subscribed_lists' );
			if ( ! empty( $newsletter_subscribed_lists ) && gettype( $newsletter_subscribed_lists ) === 'string' ) {
				$newsletter_subscribed_lists = json_decode( $newsletter_subscribed_lists );
			}
			if ( ! empty( $newsletter_subscribed_lists ) && is_array( $newsletter_subscribed_lists ) ) {
				$newsletter_subscribed_lists = array_diff( $newsletter_subscribed_lists, $data['lists_removed'] );
			}
			self::update_item( $data['user_id'], 'is_newsletter_subscriber', ! empty( $newsletter_subscribed_lists ) );
			self::update_item( $data['user_id'], 'newsletter_subscribed_lists', wp_json_encode( $newsletter_subscribed_lists ) );
		}
	}

	/**
	 * Set the user as a donor.
	 *
	 * @param int   $timestamp Timestamp.
	 * @param array $data      Data.
	 */
	public static function set_is_donor( $timestamp, $data ) {
		self::update_item( $data['user_id'], 'is_donor', true );
		self::update_item( $data['user_id'], 'is_former_donor', false );
	}

	/**
	 * Set the user as a former donor.
	 *
	 * @param int   $timestamp Timestamp.
	 * @param array $data      Data.
	 */
	public static function set_is_former_donor( $timestamp, $data ) {
		self::update_item( $data['user_id'], 'is_donor', false );
		self::update_item( $data['user_id'], 'is_former_donor', true );
	}

	/**
	 * Setup reader activity for push.
	 */
	public static function setup_reader_activity() {
		self::$reader_activity = [];

		/**
		 * Article view activity.
		 */
		if ( is_singular( 'post' ) ) {
			global $post;
			$activity = [
				'action' => 'article_view',
				'data'   => [
					'post_id'    => get_the_ID(),
					'permalink'  => get_permalink(),
					'categories' => wp_get_post_categories( get_the_ID(), [ 'fields' => 'ids' ] ),
					'tags'       => wp_get_post_tags( get_the_ID(), [ 'fields' => 'ids' ] ),
					'author'     => $post->post_author,
				],
			];

			/**
			 * Filters the 'article_view' reader activity.
			 *
			 * @param array $activity Activity.
			 */
			$activity = apply_filters( 'newspack_reader_activity_article_view', $activity );

			// Allow the filter to short-circuit the activity.
			if ( ! empty( $activity ) ) {
				self::$reader_activity[] = $activity;
			}
		}

		/**
		 * Filter the reader activity to push to the client.
		 *
		 * @param array $reader_activity Reader activity.
		 */
		self::$reader_activity = apply_filters( 'newspack_reader_activity', self::$reader_activity );
	}

	/**
	 * Data event handler to check if the user is subscribed to a newsletter and
	 * set the data item.
	 *
	 * @param int   $timestamp Timestamp.
	 * @param array $data      Data.
	 */
	public static function check_newsletter_subscription( $timestamp, $data ) {
		if ( empty( $data['user_id'] ) || empty( $data['email'] ) ) {
			return;
		}
		if ( ! class_exists( '\Newspack_Newsletters' ) || ! class_exists( '\Newspack_Newsletters_Subscription' ) ) {
			return;
		}
		$subscribed_lists = \Newspack_Newsletters_Subscription::get_contact_lists( $data['email'] );
		if ( ! is_wp_error( $subscribed_lists ) && is_array( $subscribed_lists ) ) {
			self::update_item( $data['user_id'], 'is_newsletter_subscriber', ! empty( $subscribed_lists ) );
			self::update_item( $data['user_id'], 'newsletter_subscribed_lists', wp_json_encode( $subscribed_lists ) );
		}
	}

	/**
	 * Data event handler to update a user's list of active non-donation subscriptions.
	 * The active_subscriptions key stores an array of the user's active non-donation subscriptions.
	 *
	 * @param int   $timestamp Timestamp.
	 * @param array $data      Data.
	 */
	public static function update_active_subscriptions( $timestamp, $data ) {
		if ( empty( $data['user_id'] ) || empty( $data['subscription_id'] ) || empty( $data['product_ids'] ) || empty( $data['status_after'] ) ) {
			return;
		}

		$existing_subscriptions = self::get_data( $data['user_id'], 'active_subscriptions' );
		$active_subscriptions   = $existing_subscriptions ? json_decode( $existing_subscriptions ) : [];
		if ( WooCommerce_Connection::is_subscription_active( $data['status_after'] ) ) {
			$active_subscriptions = array_merge( $active_subscriptions, $data['product_ids'] );
		} else {
			$active_subscriptions = array_values( array_diff( $active_subscriptions, $data['product_ids'] ) );
		}

		$active_subscriptions = array_values( array_unique( $active_subscriptions ) );
		self::update_item( $data['user_id'], 'active_subscriptions', $active_subscriptions );
	}

	/**
	 * Data event handler to check if the user has active subscriptions and
	 * set the data item on login.
	 *
	 * @param int   $timestamp Timestamp.
	 * @param array $data      Data.
	 */
	public static function check_product_subscriptions( $timestamp, $data ) {
		if ( empty( $data['user_id'] ) || empty( $data['email'] ) ) {
			return;
		}

		if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
			return;
		}

		$active_subscriptions = \wcs_get_subscriptions(
			[
				'customer_id'         => $data['user_id'],
				'subscription_status' => WooCommerce_Connection::ACTIVE_SUBSCRIPTION_STATUSES,
			]
		);

		if ( empty( $active_subscriptions ) ) {
			return;
		}

		$subscription_products = [];
		foreach ( $active_subscriptions as $subscription ) {
			$subscription_products = array_merge( $subscription_products, \Newspack\WooCommerce_Connection::get_products_for_order( $subscription->get_id() ) );
		}
		$subscription_products = array_values( array_unique( $subscription_products ) );
		self::update_item( $data['user_id'], 'active_subscriptions', $subscription_products );
	}

	/**
	 * Data event handler to update a user's list of active memberships.
	 *
	 * @param int   $timestamp Timestamp.
	 * @param array $data      Data.
	 */
	public static function update_active_memberships( $timestamp, $data ) {
		if ( empty( $data['user_id'] ) || empty( $data['plan_id'] ) ) {
			return;
		}

		$existing_memberships = self::get_data( $data['user_id'], 'active_memberships' );
		$active_memberships   = $existing_memberships ? json_decode( $existing_memberships ) : [];
		if ( ! isset( $data['status_after'] ) || in_array( $data['status_after'], Memberships::$active_statuses, true ) ) {
			$active_memberships[] = $data['plan_id'];
		} else {
			$active_memberships = array_values( array_diff( $active_memberships, [ $data['plan_id'] ] ) );
		}

		$active_memberships = array_values( array_unique( $active_memberships ) );
		self::update_item( $data['user_id'], 'active_memberships', $active_memberships );
	}

	/**
	 * Data event handler to check if the user has active memberships and
	 * set the data item on login.
	 *
	 * @param int   $timestamp Timestamp.
	 * @param array $data      Data.
	 */
	public static function check_memberships( $timestamp, $data ) {
		if ( empty( $data['user_id'] ) || empty( $data['email'] ) ) {
			return;
		}

		if ( ! class_exists( 'WC_Memberships' ) || ! function_exists( 'wc_memberships_get_user_memberships' ) ) {
			return;
		}

		$active_memberships = \wc_memberships_get_user_memberships( $data['user_id'], Memberships::$active_statuses );
		if ( empty( $active_memberships ) ) {
			return;
		}

		$membership_plans = [];
		foreach ( $active_memberships as $membership ) {
			$membership_plans[] = $membership->get_plan_id();
		}
		$membership_plans = array_values( array_unique( $membership_plans ) );
		self::update_item( $data['user_id'], 'active_memberships', $membership_plans );
	}
}
Reader_Data::init();
