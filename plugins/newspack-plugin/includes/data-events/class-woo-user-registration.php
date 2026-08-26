<?php
/**
 * Newspack Data Events Woo Registration hooks.
 *
 * @package Newspack
 */

namespace Newspack\Data_Events;

use Newspack\Reader_Activation;

/**
 * Class that triggers a registration event with Newspack metadata for users registered during the Woocommerce checkout process.
 */
final class Woo_User_Registration {

	/**
	 * Whether a classic checkout is in flight.
	 *
	 * Raised by woocommerce_checkout_process and never lowered. The classic
	 * pipeline runs one checkout per request and can create more than one
	 * account in it — Subscriptions Gifting creates the recipient on the
	 * order-status transition — and both are that checkout's to announce.
	 *
	 * @var boolean
	 */
	private static $processing_checkout = false;

	/**
	 * Whether a Store API checkout is in flight.
	 *
	 * @var boolean
	 */
	private static $store_api_checkout = false;

	/**
	 * Folded billing email the in-flight Store API checkout expects to create.
	 *
	 * Empty means the signal carried none, in which case the first account
	 * created stands in — see claim_account().
	 *
	 * @var string
	 */
	private static $store_api_expected_email = '';

	/**
	 * Metadata to send with the registration event.
	 *
	 * @var array
	 */
	private static $metadata = [];

	/**
	 * Initialize the class.
	 *
	 * @return void
	 */
	public static function init() {
		// is processing checkout?
		add_action( 'woocommerce_checkout_process', [ __CLASS__, 'checkout_process' ] );

		// The Store API checkout — the transport express wallets like Apple Pay and
		// Google Pay submit through — never fires woocommerce_checkout_process. This
		// action is that pipeline's equivalent signal: it fires once per checkout
		// POST, before the account is created, while the cart is loaded for the
		// metadata harvest. It carries the customer, whose billing email is what
		// tells the created account apart from any other in the same process.
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', [ __CLASS__, 'store_api_checkout_process' ], 10, 2 );

		// created a user?
		add_action( 'woocommerce_created_customer', [ __CLASS__, 'created_customer' ], 1 );

		// checkout order processed?
		add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'checkout_order_processed' ] );
	}

	/**
	 * Note a classic checkout is under way.
	 *
	 * @return void
	 */
	public static function checkout_process() {
		self::harvest_cart_metadata();
		self::$processing_checkout = true;
	}

	/**
	 * Note a Store API checkout is under way, and whose account it expects to create.
	 *
	 * Unlike the classic signal this one carries the customer, so the account
	 * this checkout creates can be told apart from any other created in the same
	 * process. WooCommerce writes the posted billing email onto the customer
	 * before firing this, and refuses to create an account without one, so an
	 * empty email here means no account will follow.
	 *
	 * @param object|null $customer WC_Customer the checkout is building.
	 * @param object|null $request  The checkout request. Unused; part of the action signature.
	 * @return void
	 */
	public static function store_api_checkout_process( $customer = null, $request = null ) {
		self::harvest_cart_metadata();
		self::$store_api_checkout       = true;
		self::$store_api_expected_email = is_object( $customer ) && method_exists( $customer, 'get_billing_email' )
			? self::fold_email( $customer->get_billing_email() )
			: '';
	}

	/**
	 * Read the campaign attribution the current checkout's cart carries.
	 *
	 * @return void
	 */
	private static function harvest_cart_metadata() {

		// One signal, one checkout: the Store API's batch route serves several
		// checkouts in a single PHP process, so metadata harvested for an
		// earlier one must not be attributed to this reader.
		self::$metadata = [];

		/**
		 * On Newspack\Donations::process_donation_request(), we add these values to the cart.
		 *
		 * Later, we add them to the order (Newspack\Donations::checkout_create_order_line_item()) and use it to send the metadata to Newspack on donation events.
		 *
		 * Here, we are going to read the same information from the cart and use it to send the metadata to Newspack on registration events.
		 */
		// Cart presence is the calling pipeline's guarantee, not ours — without
		// one there is simply no campaign metadata to harvest. Announcing the
		// reader does not depend on it; the callers set their state either way.
		if ( ! empty( \WC()->cart ) ) {
			foreach ( \WC()->cart->get_cart() as $cart_item_key => $values ) {
				if ( ! empty( $values['newspack_popup_id'] ) ) {
					self::$metadata['newspack_popup_id'] = $values['newspack_popup_id'];
				}
				if ( ! empty( $values['prompt_title'] ) ) {
					self::$metadata['prompt_title'] = $values['prompt_title'];
				}
				if ( ! empty( $values['referer'] ) ) {
					self::$metadata['referer'] = $values['referer'];
				}
			}
		}
	}

	/**
	 * Decide whether the in-flight checkout is the one that created this account.
	 *
	 * The two pipelines answer this differently, on purpose. The classic signal
	 * carries nothing identifying the buyer, so it keeps the long-standing rule
	 * — any account created while a classic checkout is in flight is that
	 * checkout's — and announces every one of them. The Store API signal fires
	 * on every checkout POST, including a logged-in shopper's where no account
	 * follows, so leaving it standing would let it speak for an unrelated
	 * account later in the same process. It announces only the account matching
	 * the email it carried, and stands down once it has.
	 *
	 * @param string $email The created account's email address.
	 * @return bool Whether this checkout announces this account.
	 */
	private static function claim_account( $email ) {
		if ( self::$store_api_checkout ) {
			// No email on the signal, so nothing to match against and the first
			// account created stands in. Unreachable on the announce path today:
			// WooCommerce will not create an account without a billing email, so a
			// signal without one is followed by no account at all.
			if ( '' === self::$store_api_expected_email ) {
				self::$store_api_checkout = false;
				return true;
			}

			if ( self::fold_email( $email ) === self::$store_api_expected_email ) {
				self::$store_api_checkout       = false;
				self::$store_api_expected_email = '';
				return true;
			}
		}

		return self::$processing_checkout;
	}

	/**
	 * Fold an email address for comparison.
	 *
	 * The two sides arrive by different routes — one off the customer object as
	 * posted, the other off the saved user record — so they are compared folded
	 * rather than raw. A mismatch would drop the announcement silently and
	 * restore the original bug with the suite still green.
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	private static function fold_email( $email ) {
		return strtolower( trim( (string) $email ) );
	}

	/**
	 * Announce an account a checkout created, with what is known about where it came from.
	 *
	 * @param int $user_id The ID of the created user.
	 * @return void
	 */
	public static function created_customer( $user_id ) {
		if ( ! self::$processing_checkout && ! self::$store_api_checkout ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return;
		}

		if ( ! self::claim_account( $user->user_email ) ) {
			return;
		}

		// Every account a checkout creates carries the method. The page and
		// campaign below are best-effort on top of it, and depend on what the
		// cart happened to hold.
		self::$metadata['registration_method'] = 'woocommerce';

		// For modal checkout, the referer is actually what we want to capture as the registration page.
		if ( ! empty( self::$metadata['referer'] ) && method_exists( 'Newspack_Blocks\Modal_Checkout', 'is_modal_checkout' ) && \Newspack_Blocks\Modal_Checkout::is_modal_checkout() ) {
			self::$metadata['current_page_url'] = self::$metadata['referer'];
		}

		if ( isset( self::$metadata['registration_method'] ) ) {
			\update_user_meta( $user_id, Reader_Activation::REGISTRATION_METHOD, self::$metadata['registration_method'] );
		}

		if ( isset( self::$metadata['current_page_url'] ) ) {
			\update_user_meta( $user_id, Reader_Activation::REGISTRATION_PAGE, self::$metadata['current_page_url'] );
		}

		/**
		 * Action after registering and authenticating a reader via Woocommerce checkout.
		 *
		 * @param string         $email         Email address.
		 * @param false|int      $user_id       The created user id.
		 * @param array          $metadata      Metadata.
		 */
		\do_action( 'newspack_registered_reader_via_woo', $user->user_email, $user_id, self::$metadata );
	}

	/**
	 * After a checkout order is processed, prevent the modal checkout from reloading.
	 *
	 * @param int $order_id The ID of the processed order.
	 * @return void
	 */
	public static function checkout_order_processed( $order_id ) {
		// Prevent the modal checkout from reloading after the user is created.
		if ( method_exists( 'Newspack_Blocks\Modal_Checkout', 'is_modal_checkout' ) && \Newspack_Blocks\Modal_Checkout::is_modal_checkout() ) {
			\WC()->session->set( 'reload_checkout', null );
		}
	}
}

Woo_User_Registration::init();
