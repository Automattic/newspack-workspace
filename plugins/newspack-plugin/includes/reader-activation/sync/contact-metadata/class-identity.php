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
	 * Rich per-field configuration.
	 *
	 * @return array
	 */
	public static function get_fields_config() {
		return [
			'first_name'        => [
				'name'        => 'First name',
				'description' => __( 'Reader\'s first name', 'newspack-plugin' ),
				'example'     => 'Claudiu',
				'status'      => 'existing',
			],
			'last_name'         => [
				'name'        => 'Last name',
				'description' => __( 'Reader\'s last name', 'newspack-plugin' ),
				'example'     => 'Lodromanean',
				'status'      => 'existing',
			],
			'email'             => [
				'name'        => 'Email',
				'description' => __( 'Reader\'s email address', 'newspack-plugin' ),
				'example'     => 'claudiu@example.com',
				'status'      => 'existing',
			],
			'Account'           => [
				'name'        => 'Account',
				'description' => __( 'WordPress user account ID of the reader', 'newspack-plugin' ),
				'example'     => '323',
				'status'      => 'existing',
				'supersedes'  => 'v1:account',
				'equivalent'  => true,
			],
			'User_Role'         => [
				'name'        => 'User Role',
				'description' => __( 'WordPress role. One of: subscriber, contributor, admin, etc.', 'newspack-plugin' ),
				'example'     => 'subscriber',
				'status'      => 'new',
			],
			'verified'          => [
				'name'        => 'Verified',
				'description' => __( 'Whether the reader has verified their account via email link', 'newspack-plugin' ),
				'example'     => 'TRUE',
				'status'      => 'new',
			],
			'Connected_Account' => [
				'name'        => 'Connected Account',
				'description' => __( 'SSO service used to register, if applicable (e.g. google, apple)', 'newspack-plugin' ),
				'example'     => 'google',
				'status'      => 'existing',
				'supersedes'  => 'v1:connected_account',
				'equivalent'  => true,
			],
		];
	}

	/**
	 * Get the metadata for the given user, customer or order.
	 *
	 * `Account` and `Connected Account` are value-equivalent to their legacy
	 * twins (v1:account / v1:connected_account), so both must reproduce the
	 * legacy pipeline's value semantics exactly — a migrated site can be
	 * pushing either version's id, and the ESP field is the same one.
	 *
	 * @return array
	 */
	public function get_metadata() {
		if ( ! $this->user ) {
			return [];
		}

		$roles = $this->user->roles;

		$metadata = [
			'first_name' => $this->user->first_name,
			'last_name'  => $this->user->last_name,
			'email'      => $this->user->user_email,
			// Integer, matching the legacy twin's $customer->get_id().
			'Account'    => (int) $this->user->ID,
			'User_Role'  => ! empty( $roles ) ? reset( $roles ) : '',
			'verified'   => (bool) Reader_Activation::is_reader_verified( $this->user ),
		];

		// Omitted unless the reader actually signed in with a supported SSO
		// provider, mirroring the legacy enrichment
		// (Metadata::add_registration_data_raw()). An empty string here would
		// blank a live merge field at any provider that overwrites on blank.
		$connected_account = $this->get_connected_account();
		if ( '' !== $connected_account ) {
			$metadata['Connected_Account'] = $connected_account;
		}

		return $metadata;
	}

	/**
	 * The SSO provider the reader is connected through, or an empty string.
	 *
	 * Port of the legacy enrichment's two-source rule: the connected-account
	 * meta when it names a supported SSO provider, otherwise the registration
	 * method when that does — readers who registered through SSO only ever get
	 * the latter (see Reader_Activation::register_reader()).
	 *
	 * @return string
	 */
	private function get_connected_account() {
		$connected_account = (string) \get_user_meta( $this->user->ID, Reader_Activation::CONNECTED_ACCOUNT, true );
		if ( in_array( $connected_account, Reader_Activation::SSO_REGISTRATION_METHODS, true ) ) {
			return $connected_account;
		}
		$registration_method = (string) \get_user_meta( $this->user->ID, Reader_Activation::REGISTRATION_METHOD, true );
		return in_array( $registration_method, Reader_Activation::SSO_REGISTRATION_METHODS, true ) ? $registration_method : '';
	}
}
