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
		return __( 'Legacy', 'newspack-plugin' );
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
		return [
			'account'              => [
				'name'        => 'Account',
				'description' => __( 'WordPress user account ID of the reader.', 'newspack-plugin' ),
				'example'     => '323',
				'status'      => 'legacy',
			],
			'registration_date'    => [
				'name'        => 'Registration Date',
				'description' => __( 'Date the reader created their account.', 'newspack-plugin' ),
				'example'     => '2022-09-19 10:00:00',
				'status'      => 'legacy',
			],
			'connected_account'    => [
				'name'        => 'Connected Account',
				'description' => __( 'SSO service used to register, if applicable (e.g. google, apple).', 'newspack-plugin' ),
				'example'     => 'google',
				'status'      => 'legacy',
			],
			'signup_page_utm'      => [
				'name'           => 'Signup UTM: ',
				'description'    => __( 'UTM parameters present on the signup page, synced as one field per parameter.', 'newspack-plugin' ),
				'example'        => 'facebook',
				'status'         => 'legacy',
				'dynamic_suffix' => true,
			],
			'newsletter_selection' => [
				'name'        => 'Newsletter Selection',
				'description' => __( 'Comma-separated list of the newsletter lists the reader is subscribed to.', 'newspack-plugin' ),
				'example'     => 'News Update, Weather Alerts',
				'status'      => 'legacy',
			],
			'referer'              => [
				'name'        => 'Referrer Path',
				'description' => __( 'Referring page URL captured when the reader submitted a signup or donation form.', 'newspack-plugin' ),
				'example'     => 'https://example.com/newsletter-signup',
				'status'      => 'legacy',
			],
			'registration_page'    => [
				'name'        => 'Registration Page',
				'description' => __( 'URL of the page where the reader registered.', 'newspack-plugin' ),
				'example'     => 'https://example.com/newsletter',
				'status'      => 'legacy',
			],
			'current_page_url'     => [
				'name'        => 'Registration Page',
				'description' => __( 'URL of the page the reader was on when they registered; an alternate source for the same value as Registration Page.', 'newspack-plugin' ),
				'example'     => 'https://example.com/newsletter',
				'status'      => 'legacy',
			],
			'registration_method'  => [
				'name'        => 'Registration Method',
				'description' => __( 'How the reader registered (e.g. registration wall, newsletter, checkout, popup, manual, or an SSO provider).', 'newspack-plugin' ),
				'example'     => 'newsletter',
				'status'      => 'legacy',
			],
		];
	}

	/**
	 * Get the metadata for the given user, customer or order, as raw keys.
	 *
	 * Enrichment, filtering and prefixing happen centrally afterwards, in
	 * normalize_contact_data() and the integration's prepare_contact().
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

		// Enrichment (add_registration_data_raw/add_utm_data_raw) happens
		// centrally in Metadata::get_contact_with_metadata(); redundant here.
		return $contact['metadata'] ?? [];
	}
}
