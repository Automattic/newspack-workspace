<?php
/**
 * Legacy payment contact metadata fields.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Sync\Contact_Metadata;

use Newspack\Donations;
use Newspack\Reader_Activation\Sync\Contact_Metadata;

defined( 'ABSPATH' ) || exit;

/**
 * Legacy Payment metadata class.
 */
class Legacy_Payment extends Contact_Metadata {

	/**
	 * Whether or not the metadata fields of this class are available to be synced.
	 *
	 * @return boolean
	 */
	public static function is_available() {
		return Donations::is_platform_wc();
	}

	/**
	 * The name of the metadata class, used as a section name for the fields handled by this class when syncing and in the UI for selecting which fields to sync.
	 *
	 * @return string
	 */
	public static function get_section_name() {
		return ''; // Legacy fields are not separated into sections.
	}

	/**
	 * The fields handled by this metadata class.
	 *
	 * @return array
	 */
	public static function get_fields() {
		return [
			'membership_status'   => 'Membership Status',
			'payment_page'        => 'Payment Page',
			'payment_page_utm'    => 'Payment UTM: ',
			'sub_start_date'      => 'Current Subscription Start Date',
			'sub_end_date'        => 'Current Subscription End Date',
			'cancellation_reason' => 'Subscription Cancellation Reason',
			'billing_cycle'       => 'Billing Cycle',
			'recurring_payment'   => 'Recurring Payment',
			'last_payment_date'   => 'Last Payment Date',
			'last_payment_amount' => 'Last Payment Amount',
			'product_name'        => 'Product Name',
			'next_payment_date'   => 'Next Payment Date',
			'total_paid'          => 'Total Paid',
		];
	}

	/**
	 * Rich per-field configuration.
	 *
	 * @return array
	 */
	public static function get_fields_config() {
		$config = parent::get_fields_config();
		if ( isset( $config['signup_page_utm'] ) ) {
			$config['signup_page_utm']['dynamic_suffix'] = true;
		}
		if ( isset( $config['payment_page_utm'] ) ) {
			$config['payment_page_utm']['dynamic_suffix'] = true;
		}
		return $config;
	}

	/**
	 * Get the metadata for the given user, customer or order.
	 *
	 * This method intentionally returns an empty array. Legacy_Basic already
	 * populates all legacy fields (both basic and payment) via
	 * WooCommerce::get_contact_from_customer(). This class exists only so
	 * payment fields appear in the UI field selection via get_fields().
	 *
	 * @return array
	 */
	public function get_metadata() {
		return [];
	}
}
