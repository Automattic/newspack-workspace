<?php
/**
 * Identity contact metadata fields.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Sync\Contact_Metadata;

use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Sync\Contact_Metadata;

defined( 'ABSPATH' ) || exit;

/**
 * Identity metadata class.
 */
class Identity extends Contact_Metadata {

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
		return __( 'Identity', 'newspack' );
	}

	/**
	 * The fields handled by this metadata class.
	 *
	 * @return array
	 */
	public static function get_fields() {
		return [
			'first_name'        => 'First name',
			'last_name'         => 'Last name',
			'email'             => 'Email',
			'Account'           => 'Account',
			'User_Role'         => 'User Role',
			'verified'          => 'Verified',
			'Connected_Account' => 'Connected Account',
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

		$roles = $this->user->roles;

		return [
			'first_name'        => $this->user->first_name,
			'last_name'         => $this->user->last_name,
			'email'             => $this->user->user_email,
			'Account'           => (string) $this->user->ID,
			'User_Role'         => ! empty( $roles ) ? reset( $roles ) : '',
			'verified'          => (bool) Reader_Activation::is_reader_verified( $this->user ),
			'Connected_Account' => (string) \get_user_meta( $this->user->ID, Reader_Activation::CONNECTED_ACCOUNT, true ),
		];
	}
}
