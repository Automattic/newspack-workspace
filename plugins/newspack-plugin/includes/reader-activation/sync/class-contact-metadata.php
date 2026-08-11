<?php
/**
 * Base class for all the contact metadata classes for Reader Activation Sync.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Sync;

use Newspack\Reader_Activation;

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
	 * Get the full name for the contact, preferring the WC_Customer billing name.
	 *
	 * Falls back to the WP user's first/last name, then a display name the
	 * reader actually has, so readers without a WooCommerce billing record
	 * (e.g. created by frontend registration integrations) don't sync an empty
	 * name that clears the ESP contact's name. A reader who registered with no
	 * name at all has neither, and returns '' rather than a stand-in.
	 *
	 * @return string
	 */
	public function get_full_name() {
		if ( $this->customer ) {
			$name = trim( $this->customer->get_billing_first_name() . ' ' . $this->customer->get_billing_last_name() );
			if ( $name ) {
				return $name;
			}
		}
		if ( $this->user ) {
			$name = trim( $this->user->first_name . ' ' . $this->user->last_name );
			if ( $name ) {
				return $name;
			}
			if ( ! $this->has_email_derived_display_name() ) {
				return (string) $this->user->display_name;
			}
		}
		return '';
	}

	/**
	 * Whether the user's display name is a placeholder generated from their email.
	 *
	 * Accounts are named after the email when the reader supplies no name, so
	 * display_name is `jane-doe` for jane.doe@example.com. That is a
	 * placeholder, not a name: syncing it would write the local part into the
	 * ESP's first-name field, overwriting whatever is there — including a name
	 * that arrived by list import.
	 *
	 * Applies the reader's own saved-name meta as a short-circuit, but not the
	 * NEWSPACK_ALLOW_GENERIC_READER_DISPLAY_NAMES constant that
	 * Reader_Activation::reader_has_generic_display_name() also honors: the
	 * constant answers whether to stop prompting readers for a real name, and
	 * a site setting it would otherwise put the placeholder back on the wire.
	 *
	 * @return bool
	 */
	private function has_email_derived_display_name(): bool {
		$display_name = (string) $this->user->display_name;
		$email        = (string) $this->user->user_email;
		if ( '' === $display_name || '' === $email ) {
			return true;
		}
		// A reader who deliberately saved a display name we would call generic
		// has chosen it; it is their name, and it syncs.
		if ( \get_user_meta( $this->user->ID, Reader_Activation::READER_SAVED_GENERIC_DISPLAY_NAME, true ) ) {
			return false;
		}
		return Reader_Activation::is_display_name_derived_from_email( $display_name, $email );
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
