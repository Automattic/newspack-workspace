<?php
/**
 * Registration contact metadata fields.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Sync\Contact_Metadata;

use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Sync\Contact_Metadata;

defined( 'ABSPATH' ) || exit;

/**
 * Registration metadata class.
 */
class Registration extends Contact_Metadata {

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
		return __( 'Registration', 'newspack' );
	}

	/**
	 * The fields handled by this metadata class.
	 *
	 * @return array
	 */
	public static function get_fields() {
		return [
			'Registration_Date'         => 'Registration Date',
			'Registration_Page'         => 'Registration Page',
			'Registration_Strategy'     => 'Registration Strategy',
			'Registration_UTM_Source'   => 'Registration UTM Source',
			'Registration_UTM_Medium'   => 'Registration UTM Medium',
			'Registration_UTM_Campaign' => 'Registration UTM Campaign',
		];
	}

	/**
	 * Get the metadata for the given user, customer or order.
	 *
	 * @return array
	 */
	public function get_metadata() {
		if ( ! $this->user ) {
			return [];
		}

		return [
			'Registration_Date'         => $this->format_date( $this->user->user_registered ),
			'Registration_Page'         => (string) \get_user_meta( $this->user->ID, Reader_Activation::REGISTRATION_PAGE, true ),
			'Registration_Strategy'     => (string) \get_user_meta( $this->user->ID, Reader_Activation::REGISTRATION_METHOD, true ),
			'Registration_UTM_Source'   => $this->get_registration_utm( 'utm_source' ),
			'Registration_UTM_Medium'   => $this->get_registration_utm( 'utm_medium' ),
			'Registration_UTM_Campaign' => $this->get_registration_utm( 'utm_campaign' ),
		];
	}

	/**
	 * Get a registration UTM parameter from user meta.
	 *
	 * @param string $param UTM parameter name (e.g. 'utm_source').
	 * @return string UTM value or empty string.
	 */
	private function get_registration_utm( $param ) {
		$meta_keys = [
			'utm_source'   => Reader_Activation::REGISTRATION_UTM_SOURCE,
			'utm_medium'   => Reader_Activation::REGISTRATION_UTM_MEDIUM,
			'utm_campaign' => Reader_Activation::REGISTRATION_UTM_CAMPAIGN,
		];
		if ( ! isset( $meta_keys[ $param ] ) ) {
			return '';
		}
		$value = (string) \get_user_meta( $this->user->ID, $meta_keys[ $param ], true );
		if ( ! empty( $value ) ) {
			return $value;
		}
		// Fallback: parse UTM from the stored registration page URL for readers registered before UTM meta was saved.
		$registration_page = (string) \get_user_meta( $this->user->ID, Reader_Activation::REGISTRATION_PAGE, true );
		if ( ! empty( $registration_page ) ) {
			$parsed = \wp_parse_url( $registration_page );
			if ( ! empty( $parsed['query'] ) ) {
				$query_params = [];
				\wp_parse_str( $parsed['query'], $query_params );
				if ( ! empty( $query_params[ $param ] ) ) {
					return \sanitize_text_field( $query_params[ $param ] );
				}
			}
		}
		return '';
	}
}
