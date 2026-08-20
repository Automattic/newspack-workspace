<?php
/**
 * Legacy basic contact metadata fields.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Sync\Contact_Metadata;

use Newspack\Reader_Activation\Sync\Contact_Metadata;
use Newspack\Reader_Activation\Sync\Legacy_Metadata;
use Newspack\Reader_Activation\Sync\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Legacy Basic metadata class.
 */
class Legacy_Basic extends Contact_Metadata {

	/**
	 * Whether or not the metadata fields of this class are available to be synced.
	 *
	 * @return boolean
	 */
	public static function is_available() {
		return true;
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
		return Legacy_Metadata::get_basic_fields();
	}

	/**
	 * Per-field configuration for the fields handled by this class.
	 *
	 * @return array
	 */
	public static function get_fields_config() {
		return [
			'account'              => [
				'name'   => 'Account',
				'status' => 'legacy',
			],
			'registration_date'    => [
				'name'   => 'Registration Date',
				'status' => 'legacy',
			],
			'connected_account'    => [
				'name'   => 'Connected Account',
				'status' => 'legacy',
			],
			'signup_page_utm'      => [
				'name'           => 'Signup UTM: ',
				'status'         => 'legacy',
				'dynamic_suffix' => true,
			],
			'newsletter_selection' => [
				'name'   => 'Newsletter Selection',
				'status' => 'legacy',
			],
			'referer'              => [
				'name'   => 'Referrer Path',
				'status' => 'legacy',
			],
			'registration_page'    => [
				'name'   => 'Registration Page',
				'status' => 'legacy',
			],
			'current_page_url'     => [
				'name'   => 'Registration Page',
				'status' => 'legacy',
			],
			'registration_method'  => [
				'name'   => 'Registration Method',
				'status' => 'legacy',
			],
		];
	}

	/**
	 * Get the metadata for the given user, customer or order.
	 *
	 * Delegates to the legacy WooCommerce and normalization logic to build
	 * the full set of legacy metadata fields.
	 *
	 * @return array
	 */
	public function get_metadata() {
		if ( ! $this->customer ) {
			return [];
		}

		$contact = WooCommerce::get_contact_from_customer( $this->customer );
		if ( ! $contact ) {
			return [];
		}

		$contact = Legacy_Metadata::normalize_contact_data( $contact );

		return $contact['metadata'] ?? [];
	}
}
