<?php
/**
 * Base class for all the contact metadata classes for Reader Activation Sync.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Reader Activation Class.
 */
abstract class Contact_Metadata {
	/**
	 * The date format to use for all date fields, which is YYYY-MM-DD HH:MM:SS.
	 */
	const DATE_FORMAT = 'Y-m-d H:i:s';

	/**
	 * The WP_User object.
	 *
	 * @var \WP_User|false
	 */
	protected $user = false;

	/**
	 * The WC_Customer object.
	 *
	 * @var \WC_Customer|false
	 */
	protected $customer = false;

	/**
	 * The WC_Order object.
	 *
	 * @var \WC_Order|false
	 */
	protected $order = false;

	/**
	 * Contact_Metadata constructor.
	 *
	 * @param \WP_User|\WC_Customer|\WC_Order|int $user_customer_or_order WP_User, WC_Customer, WC_Order object or ID of the user, customer or order to get the metadata for.
	 */
	public function __construct( $user_customer_or_order ) {
		if ( $user_customer_or_order instanceof \WC_Order ) {
			$this->order = $user_customer_or_order;
			$user_id     = $this->order->get_customer_id();
		} elseif ( $user_customer_or_order instanceof \WC_Customer ) {
			$this->customer = $user_customer_or_order;
			$user_id        = $this->customer->get_id();
		} elseif ( $user_customer_or_order instanceof \WP_User ) {
			$this->user = $user_customer_or_order;
			$user_id    = $this->user->ID;
		} else {
			$user_id = (int) $user_customer_or_order;
		}

		if ( ! $this->user && $user_id ) {
			$this->user = \get_user_by( 'id', $user_id );
		}

		if ( ! $this->customer && $user_id && class_exists( 'WC_Customer' ) ) {
			$this->customer = new \WC_Customer( $user_id );
			if ( ! $this->customer->get_id() ) {
				$this->customer = false;
			}
		}
	}

	/**
	 * The name of the metadata class, used as a section name for the fields handled by this class when syncing and in the UI for selecting which fields to sync.
	 *
	 * @return string
	 */
	abstract public static function get_section_name();

	/**
	 * Whether or not the metadata fields of this class are available to be synced.
	 *
	 * An example of when this might be false is when the metadata relies on a plugin that isn't active, like WooCommerce.
	 *
	 * @return boolean
	 */
	abstract public static function is_available();

	/**
	 * The fields handled by this metadata class, returned as an array of key/value pairs where the key is the key of the field that will be prefixed and synced, and the value is the human readable name of the field that will be used in the UI for selecting which fields to sync.
	 *
	 * @return array
	 */
	abstract public static function get_fields();

	/**
	 * Get the metadata for the given user, customer or order, returned as an array of key/value pairs where the key is the key of the field that will be prefixed and synced, and the value is the value of the field for that user, customer or order.
	 *
	 * @return array
	 */
	abstract public function get_metadata();

	/**
	 * Get the email address for the contact.
	 *
	 * @return string
	 */
	public function get_email() {
		if ( $this->customer ) {
			return $this->customer->get_email();
		}
		if ( $this->user ) {
			return $this->user->user_email;
		}
		return '';
	}

	/**
	 * Get the full name for the contact from the WC_Customer billing name.
	 *
	 * @return string
	 */
	public function get_full_name() {
		if ( $this->customer ) {
			return trim( $this->customer->get_billing_first_name() . ' ' . $this->customer->get_billing_last_name() );
		}
		return '';
	}

	/**
	 * Format a date string.
	 *
	 * @param string $date_string Date string.
	 * @return string Formatted date or empty string.
	 */
	protected function format_date( $date_string ) {
		if ( empty( $date_string ) || '0' === $date_string ) {
			return '';
		}
		$timestamp = strtotime( $date_string );
		if ( ! $timestamp ) {
			return '';
		}
		return gmdate( self::DATE_FORMAT, $timestamp );
	}
}
