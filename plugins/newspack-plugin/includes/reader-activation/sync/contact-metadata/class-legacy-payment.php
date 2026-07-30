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
		return [
			'membership_status'   => [
				'name'        => 'Membership Status',
				'description' => __( 'Combined membership label derived from subscriptions and donations.', 'newspack-plugin' ),
				'example'     => 'customer-2024',
				'sync_type'   => 'field',
				'status'      => 'legacy',
			],
			'payment_page'        => [
				'name'        => 'Payment Page',
				'description' => __( 'URL of the page the reader most recently checked out on.', 'newspack-plugin' ),
				'example'     => 'https://example.com/support-us',
				'sync_type'   => 'field',
				'status'      => 'legacy',
			],
			'payment_page_utm'    => [
				'name'           => 'Payment UTM: ',
				'description'    => __( 'UTM parameters present on the payment page, synced as one field per parameter.', 'newspack-plugin' ),
				'example'        => 'email',
				'sync_type'      => 'field',
				'status'         => 'legacy',
				'dynamic_suffix' => true,
			],
			'sub_start_date'      => [
				'name'        => 'Current Subscription Start Date',
				'description' => __( 'Start date of the most recent subscription of any product type.', 'newspack-plugin' ),
				'example'     => '2022-09-19 10:00:00',
				'sync_type'   => 'field',
				'status'      => 'legacy',
			],
			'sub_end_date'        => [
				'name'        => 'Current Subscription End Date',
				'description' => __( 'End or renewal date of the most recent subscription of any product type.', 'newspack-plugin' ),
				'example'     => '2023-09-19 10:00:00',
				'sync_type'   => 'field',
				'status'      => 'legacy',
			],
			'cancellation_reason' => [
				'name'        => 'Subscription Cancellation Reason',
				'description' => __( 'Reason the most recent subscription of any product type was cancelled.', 'newspack-plugin' ),
				'example'     => 'user-canceled',
				'sync_type'   => 'field',
				'status'      => 'legacy',
			],
			'billing_cycle'       => [
				'name'        => 'Billing Cycle',
				'description' => __( 'Billing frequency of the current recurring payment.', 'newspack-plugin' ),
				'example'     => 'month',
				'sync_type'   => 'field',
				'status'      => 'legacy',
			],
			'recurring_payment'   => [
				'name'        => 'Recurring Payment',
				'description' => __( 'Amount of the current recurring payment for any product type.', 'newspack-plugin' ),
				'example'     => '15',
				'sync_type'   => 'field',
				'status'      => 'legacy',
			],
			'last_payment_date'   => [
				'name'        => 'Last Payment Date',
				'description' => __( 'Date of the most recent payment for any product, including donations.', 'newspack-plugin' ),
				'example'     => '2022-09-19 10:00:00',
				'sync_type'   => 'field',
				'status'      => 'legacy',
			],
			'last_payment_amount' => [
				'name'        => 'Last Payment Amount',
				'description' => __( 'Amount of the most recent payment for any product, including donations.', 'newspack-plugin' ),
				'example'     => '15',
				'sync_type'   => 'field',
				'status'      => 'legacy',
			],
			'product_name'        => [
				'name'        => 'Product Name',
				'description' => __( 'Name of the most recently purchased product.', 'newspack-plugin' ),
				'example'     => 'Digital Supporter',
				'sync_type'   => 'field',
				'status'      => 'legacy',
			],
			'next_payment_date'   => [
				'name'        => 'Next Payment Date',
				'description' => __( 'Date of the next scheduled recurring payment for any product type.', 'newspack-plugin' ),
				'example'     => '2022-10-19 10:00:00',
				'sync_type'   => 'field',
				'status'      => 'legacy',
			],
			'total_paid'          => [
				'name'        => 'Total Paid',
				'description' => __( 'Lifetime total amount the reader has paid through WooCommerce.', 'newspack-plugin' ),
				'example'     => '120',
				'sync_type'   => 'field',
				'status'      => 'legacy',
			],
		];
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
