<?php
/**
 * Reader Activation Sync Legacy Metadata.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Sync;

use Newspack\Donations;
use Newspack\Reader_Activation;
use Newspack\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Legacy Metadata Class.
 *
 * Holds the logic for handling contact sync metadata prior the Newspack Integrations implementation.
 *
 * This serves as a fallback so we can do a smooth transition from these global options into the new per-integration selected fields, and to avoid breaking existing functionality until we can fully remove this legacy metadata handling.
 */
class Legacy_Metadata {

	/**
	 * Metadata keys map for Reader Activation.
	 *
	 * @var array
	 */
	public static $keys = [];

	/**
	 * Get the metadata keys map for Reader Activation.
	 *
	 * @return array List of fields.
	 */
	public static function get_keys() {
		if ( empty( self::$keys ) ) {
			// Only get Woo fields if using Woo.
			$fields = Donations::is_platform_wc() ? self::get_all_fields() : self::get_basic_fields();

			/**
			 * Filters the list of key/value pairs for metadata fields to be synced to the connected ESP.
			 *
			 * @param array $keys The list of key/value pairs for metadata fields to be synced to the connected ESP.
			 */
			self::$keys = \apply_filters( 'newspack_ras_metadata_keys', $fields );
		}
		return self::$keys;
	}

	/**
	 * Get all metadata fields.
	 *
	 * @return array List of fields.
	 */
	public static function get_all_fields() {
		return array_merge( self::get_basic_fields(), self::get_payment_fields() );
	}

	/**
	 * Get basic metadata fields.
	 *
	 * @return array List of fields.
	 */
	public static function get_basic_fields() {
		return [
			'account'              => 'Account',
			'registration_date'    => 'Registration Date',
			'connected_account'    => 'Connected Account',
			'signup_page_utm'      => 'Signup UTM: ',
			'newsletter_selection' => 'Newsletter Selection',
			'referer'              => 'Referrer Path',
			'registration_page'    => 'Registration Page',
			'current_page_url'     => 'Registration Page',
			'registration_method'  => 'Registration Method',
		];
	}

	/**
	 * Get payment-related metadata fields.
	 *
	 * @return array List of fields.
	 */
	public static function get_payment_fields() {
		return [
			'membership_status'   => 'Membership Status',
			// URL of the page on which the payment has happened.
			'payment_page'        => 'Payment Page',
			'payment_page_utm'    => 'Payment UTM: ',
			'sub_start_date'      => 'Current Subscription Start Date',
			'sub_end_date'        => 'Current Subscription End Date',
			'cancellation_reason' => 'Subscription Cancellation Reason',
			// At what interval does the recurring payment occur – e.g. day, week, month or year.
			'billing_cycle'       => 'Billing Cycle',
			// The total value of the recurring payment.
			'recurring_payment'   => 'Recurring Payment',
			'last_payment_date'   => 'Last Payment Date',
			'last_payment_amount' => 'Last Payment Amount',
			// Product name, as it appears in WooCommerce.
			'product_name'        => 'Product Name',
			'next_payment_date'   => 'Next Payment Date',
			// Total value spent by this customer on the site.
			'total_paid'          => 'Total Paid',
		];
	}

	/**
	 * Get the UTM key from a raw or prefixed key.
	 * The returned key must have a suffix (source, medium, campaign, content).
	 *
	 * @param string $key Key to check.
	 *
	 * @return string|false Formatted key if it is a UTM key, false otherwise.
	 */
	public static function get_utm_key( $key ) {
		$keys     = [ 'signup_page_utm', 'payment_page_utm' ];
		$raw_keys = Metadata::get_raw_keys();
		foreach ( $keys as $utm_key ) {
			if ( ! in_array( $utm_key, $raw_keys, true ) ) { // Skip if the UTM key is not in the list of fields to sync.
				continue;
			}
			$prefixed_key = Metadata::get_key( $utm_key );
			if ( 0 === strpos( $key, $utm_key ) ) {
				$suffix = str_replace( $utm_key . '_', '', $key );
				return ! empty( trim( $suffix ) ) && $suffix !== $key ? $prefixed_key . $suffix : false;
			}
			if ( 0 === strpos( $key, $prefixed_key ) && $key !== $prefixed_key ) {
				return $key;
			}
		}
		return false;
	}

	/**
	 * Add user's registration-related data to the given metadata.
	 * These won't be included in every sync request, but they might be stored as user meta.
	 *
	 * @param array $metadata Metadata to add to.
	 *
	 * @return array Metadata with registration data added.
	 */
	private static function add_registration_data( $metadata ) {
		$user = Metadata::has_key( 'account', $metadata ) ? \get_user_by( 'id', Metadata::get_key_value( 'account', $metadata ) ) : false;
		if ( ! $user ) {
			return $metadata;
		}

		$registration_method = Metadata::has_key( 'registration_method', $metadata ) ? Metadata::get_key_value( 'registration_method', $metadata ) : \get_user_meta( $user->ID, Reader_Activation::REGISTRATION_METHOD, true );
		if ( ! empty( $registration_method ) ) {
			$metadata['registration_method'] = $registration_method;
		}

		$registration_page = Metadata::has_key( 'registration_page', $metadata ) ? Metadata::get_key_value( 'registration_page', $metadata ) : \get_user_meta( $user->ID, Reader_Activation::REGISTRATION_PAGE, true );
		if ( ! empty( $registration_page ) ) {
			$metadata['registration_page'] = $registration_page;
		}

		$connected_account = Metadata::has_key( 'connected_account', $metadata ) ? Metadata::get_key_value( 'connected_account', $metadata ) : \get_user_meta( $user->ID, Reader_Activation::CONNECTED_ACCOUNT, true );
		if ( ! empty( $connected_account ) && in_array( $connected_account, Reader_Activation::SSO_REGISTRATION_METHODS ) ) {
			$metadata['connected_account'] = $connected_account;
		} elseif ( ! empty( $registration_method ) && in_array( $registration_method, Reader_Activation::SSO_REGISTRATION_METHODS ) ) {
			$metadata['connected_account'] = $registration_method;
		}

		return $metadata;
	}

	/**
	 * Add UTM fields to the given metadata.
	 *
	 * @param array $metadata Metadata to add to.
	 *
	 * @return array Metadata with UTM fields added.
	 */
	public static function add_utm_data( $metadata ) {
		// Capture UTM params and signup/payment page URLs as meta for registration or payment.
		if ( Metadata::has_key( 'current_page_url', $metadata ) || Metadata::has_key( 'registration_page', $metadata ) || Metadata::has_key( 'payment_page', $metadata ) ) {
			$payment_page = Metadata::has_key( 'payment_page', $metadata ) ? Metadata::get_key_value( 'payment_page', $metadata ) : false;
			$raw_url    = false;
			if ( ! empty( $payment_page ) ) {
				$raw_url = Metadata::get_key_value( 'payment_page', $metadata );
			} elseif ( Metadata::has_key( 'current_page_url', $metadata ) ) {
				$raw_url = Metadata::get_key_value( 'current_page_url', $metadata );
			} else {
				$raw_url = Metadata::get_key_value( 'registration_page', $metadata );
			}

			$parsed_url = \wp_parse_url( $raw_url );

			// Maybe set UTM meta.
			if ( ! empty( $parsed_url['query'] ) ) {
				$utm_key_prefix = ! empty( $payment_page ) ? 'payment_page_utm' : 'signup_page_utm';
				$params         = [];
				\wp_parse_str( $parsed_url['query'], $params );
				foreach ( $params as $param => $value ) {
					$param = \sanitize_text_field( $param );
					if ( 'utm' === substr( $param, 0, 3 ) ) {
						$param = str_replace( 'utm_', '', $param );
						$key   = Metadata::get_key( $utm_key_prefix ) . $param;
						if ( ! isset( $metadata[ $key ] ) || empty( $metadata[ $key ] ) ) {
							$metadata[ $key ] = $value;
						}
					}
				}
			}
		}

		return $metadata;
	}

	/**
	 * Normalizes contact metadata keys before syncing to ESP.
	 *
	 * @param array $contact Contact data.
	 * @return array Normalized contact data.
	 */
	public static function normalize_contact_data( $contact ) {
		if ( ! isset( $contact['metadata'] ) ) {
			$contact['metadata'] = [];
		}

		$metadata            = $contact['metadata'];
		$metadata            = self::add_registration_data( $metadata );
		$metadata            = self::add_utm_data( $metadata );
		$raw_keys            = Metadata::get_raw_keys();
		$prefixed_keys       = Metadata::get_prefixed_keys();
		$normalized_metadata = [];

		// Keys allowed to pass through without prefixing.
		$allowed_keys = [ 'status', 'status_if_new' ];

		// UTM keys must be suffixed.
		$disallowed_keys = [
			'payment_page_utm',
			'payment_page_utm_',
			'signup_page_utm',
			'signup_page_utm_',
			Metadata::get_key( 'payment_page_utm' ),
			Metadata::get_key( 'signup_page_utm' ),
		];

		foreach ( $metadata as $meta_key => $meta_value ) {
			if ( in_array( $meta_key, $raw_keys, true ) && ! in_array( $meta_key, $disallowed_keys, true ) ) { // Handle raw keys.
				$normalized_metadata[ Metadata::get_key( $meta_key ) ] = $meta_value;
			} elseif ( in_array( $meta_key, $prefixed_keys, true ) && ! in_array( $meta_key, $disallowed_keys, true ) ) { // Handle prefixed keys.
				$normalized_metadata[ $meta_key ] = $meta_value;
			} elseif ( self::get_utm_key( $meta_key ) ) { // Handle UTM keys.
				$normalized_metadata[ self::get_utm_key( $meta_key ) ] = $meta_value;
			} elseif ( in_array( $meta_key, $allowed_keys, true ) ) { // Handle allowed keys.
				$normalized_metadata[ $meta_key ] = $meta_value;
			} else { // If the key is not in the list of fields to sync, ignore it.
				Logger::log( 'Ignoring metadata key: ' . $meta_key );
			}
		}

		$contact['metadata'] = $normalized_metadata;

		Logger::log( 'Normalizing contact data for reader ESP sync:' );
		Logger::log( $contact );

		/**
		 * Filters the normalized contact data before syncing to the ESP.
		 *
		 * @param array $contact Contact data.
		 */
		return apply_filters( 'newspack_esp_sync_normalize_contact', $contact );
	}
}
