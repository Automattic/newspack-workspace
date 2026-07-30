<?php
/**
 * Legacy basic contact metadata fields.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Sync\Contact_Metadata;

use Newspack\Reader_Activation\Sync\Contact_Metadata;
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
	 * Get the metadata for the given user, customer or order, as raw keys.
	 *
	 * Builds the raw legacy contact from the WooCommerce helper. Raw-key
	 * enrichment, filtering and prefixing are handled centrally afterwards
	 * (normalize_contact_data() and the integration's prepare_contact()).
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

		// Enrichment (add_registration_data_raw/add_utm_data_raw) is applied
		// centrally in Metadata::get_contact_with_metadata() via
		// normalize_contact_data(), so applying it per-class here would be
		// redundant.
		return $contact['metadata'] ?? [];
	}
}
