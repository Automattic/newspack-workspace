<?php
/**
 * Newspack Data Events Content Gate and Memberships helper.
 *
 * @package Newspack
 */

namespace Newspack\Data_Events;

use Newspack\Content_Gate;
use Newspack\Reader_Activation;
use Newspack\Data_Events;
use WP_Error;

/**
 * Data Events Memberships Class
 */
final class Memberships {

	const METADATA_NAME = 'gate_post_id';

	/**
	 * The name of the action for form submissions
	 */
	const FORM_SUBMISSION = 'form_submission_received';

	/**
	 * The name of the action for form submissions
	 */
	const FORM_SUBMISSION_SUCCESS = 'form_submission_success';

	/**
	 * The name of the action for form submissions
	 */
	const FORM_SUBMISSION_FAILURE = 'form_submission_failure';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_listeners' ] );
		add_filter( 'newspack_blocks_modal_checkout_cart_item_data', [ __CLASS__, 'checkout_cart_item_data' ], 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'checkout_create_order_line_item' ], 10, 4 );
		add_filter( 'newspack_register_reader_metadata', [ __CLASS__, 'register_reader_metadata' ] );
		add_filter( 'newspack_auth_form_metadata', [ __CLASS__, 'register_reader_metadata' ] );
		add_filter( 'newspack_otp_login_metadata', [ __CLASS__, 'register_reader_metadata' ] );
		add_filter( 'newspack_register_reader_form_metadata', [ __CLASS__, 'register_reader_metadata' ] );
		add_filter( 'newspack_newsletters_subscription_form_metadata', [ __CLASS__, 'register_reader_metadata' ] );
	}

	/**
	 * Add content gate metadata to the cart item.
	 *
	 * @param array $cart_item_data The cart item data.
	 *
	 * @return array
	 */
	public static function checkout_cart_item_data( $cart_item_data ) {
		$gate_post_id = filter_input( INPUT_GET, self::METADATA_NAME, FILTER_SANITIZE_NUMBER_INT );
		if ( ! empty( $gate_post_id ) ) {
			$cart_item_data[ self::METADATA_NAME ] = $gate_post_id;
		}
		return $cart_item_data;
	}

	/**
	 * Add content gate metadata from the cart item to the order.
	 *
	 * @param \WC_Order_Item_Product $item The cart item.
	 * @param string                 $cart_item_key The cart item key.
	 * @param array                  $values The cart item values.
	 * @param \WC_Order              $order The order.
	 * @return void
	 */
	public static function checkout_create_order_line_item( $item, $cart_item_key, $values, $order ) {
		if ( ! empty( $values[ self::METADATA_NAME ] ) ) {
			$order->add_meta_data( '_gate_post_id', $values[ self::METADATA_NAME ] );
		}
	}

	/**
	 * Add content gate metadata on reader registration.
	 *
	 * @param array $metadata The metadata.
	 *
	 * @return array
	 */
	public static function register_reader_metadata( $metadata ) {
		$gate_post_id = filter_input( INPUT_POST, self::METADATA_NAME, FILTER_SANITIZE_NUMBER_INT );
		if ( ! empty( $gate_post_id ) && ( isset( $metadata['registration_method'] ) || isset( $metadata['login_method'] ) ) ) {
			$metadata['gate_post_id'] = $gate_post_id;
		}
		return $metadata;
	}

	/**
	 * Register listeners.
	 */
	public static function register_listeners() {
		/**
		 * Gate interaction: Registration membership
		 */
		Data_Events::register_listener(
			'newspack_registered_reader',
			'gate_interaction',
			[ __CLASS__, 'registration_submission' ]
		);
		Data_Events::register_listener(
			'newspack_registered_reader',
			'gate_interaction',
			[ __CLASS__, 'registration_submission_with_status' ]
		);

		/**
		 * Gate interaction: Paid membership
		 */
		Data_Events::register_listener(
			'woocommerce_checkout_order_processed',
			'gate_interaction',
			[ __CLASS__, 'woocommerce_checkout_order_processed' ]
		);
		Data_Events::register_listener(
			'woocommerce_order_status_failed',
			'gate_interaction',
			[ __CLASS__, 'woocommerce_order_status_failed' ]
		);
		Data_Events::register_listener(
			'woocommerce_order_status_completed',
			'gate_interaction',
			[ __CLASS__, 'woocommerce_order_status_completed' ]
		);
	}

	/**
	 * A listener for the registration block form submission
	 *
	 * Will trigger the event with "form_submission" as action in all cases.
	 *
	 * @param string         $email         Email address.
	 * @param bool           $authenticate  Whether to authenticate after registering.
	 * @param false|int      $user_id       The created user id.
	 * @param false|\WP_User $existing_user The existing user object.
	 * @param array          $metadata      Metadata.
	 *
	 * @return ?array
	 */
	public static function registration_submission( $email, $authenticate, $user_id, $existing_user, $metadata ) {
		if ( ! isset( $metadata[ self::METADATA_NAME ] ) ) {
			return;
		}
		$data = array_merge(
			Content_Gate::get_gate_metadata(),
			[
				'action'      => self::FORM_SUBMISSION,
				'action_type' => 'registration',
				'referer'     => $metadata['referer'],
			]
		);
		$data['interaction_data']['registration_method'] = $metadata['registration_method'];
		return $data;
	}

	/**
	 * A listener for the registration block form submission
	 *
	 * Will trigger the event with "form_submission" as action in all cases.
	 *
	 * @param string         $email         Email address.
	 * @param bool           $authenticate  Whether to authenticate after registering.
	 * @param false|int      $user_id       The created user id.
	 * @param false|\WP_User $existing_user The existing user object.
	 * @param array          $metadata      Metadata.
	 *
	 * @return ?array
	 */
	public static function registration_submission_with_status( $email, $authenticate, $user_id, $existing_user, $metadata ) {
		if ( ! isset( $metadata[ self::METADATA_NAME ] ) ) {
			return;
		}
		$action = self::FORM_SUBMISSION_SUCCESS;
		if ( ! $user_id || \is_wp_error( $user_id ) ) {
			$action = self::FORM_SUBMISSION_FAILURE;
		}
		$data = array_merge(
			Content_Gate::get_gate_metadata(),
			[
				'action'      => $action,
				'action_type' => 'registration',
				'referer'     => $metadata['referer'],
			]
		);
		$data['interaction_data']['registration_method'] = $metadata['registration_method'];
		return $data;
	}

	/**
	 * Get order data.
	 *
	 * @param int       $order_id The order ID.
	 * @param \WC_Order $order    The order object.
	 *
	 * @return ?array
	 */
	private static function get_order_data( $order_id, $order ) {
		$is_from_gate = $order->get_meta( '_gate_post_id' ) ? $order->get_meta( '_gate_post_id' ) : $order->get_meta( '_memberships_content_gate' ); // Handle legacy _memberships_content_gate meta key.
		if ( ! $is_from_gate ) {
			return;
		}
		$item = array_shift( $order->get_items() );
		$data = array_merge(
			Content_Gate::get_gate_metadata(),
			[
				'action_type' => 'paid_membership',
				'order_id'    => $order_id,
				'product_id'  => $item->get_product_id(),
				'amount'      => (float) $order->get_total(),
				'currency'    => $order->get_currency(),
				'referer'     => $order->get_meta( '_newspack_referer' ),
			]
		);
		return $data;
	}

	/**
	 * A listener for when a WooCommerce order is processed.
	 *
	 * @param int $order_id The order ID.
	 *
	 * @return ?array
	 */
	public static function woocommerce_checkout_order_processed( $order_id ) {
		$order = \wc_get_order( $order_id );
		if ( ! Reader_Activation\Sync\WooCommerce::should_sync_order( $order ) ) {
			return;
		}
		$data = self::get_order_data( $order_id, $order );
		if ( empty( $data ) ) {
			return;
		}
		$data['action'] = self::FORM_SUBMISSION;
		return $data;
	}

	/**
	 * A listener for when a WooCommerce order has failed.
	 *
	 * @param int       $order_id The order ID.
	 * @param \WC_Order $order    The order object.
	 *
	 * @return ?array
	 */
	public static function woocommerce_order_status_failed( $order_id, $order ) {
		$data = self::get_order_data( $order_id, $order );
		if ( empty( $data ) ) {
			return;
		}
		$data['action'] = self::FORM_SUBMISSION_FAILURE;
		return $data;
	}

	/**
	 * A listener for when a WooCommerce order is completed.
	 *
	 * @param int       $order_id The order ID.
	 * @param \WC_Order $order    The order object.
	 *
	 * @return ?array
	 */
	public static function woocommerce_order_status_completed( $order_id, $order ) {
		$data = self::get_order_data( $order_id, $order );
		if ( empty( $data ) ) {
			return;
		}
		$data['action'] = self::FORM_SUBMISSION_SUCCESS;
		return $data;
	}
}
Memberships::init();
