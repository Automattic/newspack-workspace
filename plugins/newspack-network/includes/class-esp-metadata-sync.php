<?php
/**
 * Newspack Hub ESP Metadata settings
 *
 * @package Newspack
 */

namespace Newspack_Network;

use Newspack_Network\Utils\Users as User_Utils;
use Newspack\Data_Events;

/**
 * Class to handle Node settings page
 */
class Esp_Metadata_Sync {

	/**
	 * Initialize this class and register hooks
	 *
	 * @return void
	 */
	public static function init() {
		\add_filter( 'newspack_ras_metadata_keys', [ __CLASS__, 'add_custom_metadata_fields' ] );
		\add_filter( 'newspack_esp_sync_contact', [ __CLASS__, 'handle_esp_sync_contact' ], 10, 2 );
		\add_action( 'init', [ __CLASS__, 'register_listeners' ] );
	}

	/**
	 * Register the listeners to the Newspack Data Events API
	 *
	 * @return void
	 */
	public static function register_listeners() {
		if ( ! class_exists( 'Newspack\Data_Events' ) ) {
			return;
		}

		Data_Events::register_listener( 'newspack_network_new_network_reader', 'network_new_reader', [ __CLASS__, 'new_reader_data_event' ] );
	}

	/**
	 * Adds Network-specific metadata fields to the list of ESP metadata fields.
	 *
	 * @param array $metadata_fields The list of ESP metadata fields.
	 *
	 * @return array The updated list of ESP metadata fields.
	 */
	public static function add_custom_metadata_fields( $metadata_fields ) {
		if ( ! isset( $metadata_fields['network_registration_site'] ) ) {
			$metadata_fields['network_registration_site'] = 'Network Registration Site';
		}

		return $metadata_fields;
	}

	/**
	 * Add handling for custom metadata fields when syncing to ESP.
	 *
	 * @param array $contact The contact metadata data.
	 *
	 * @return array The updated contact data.
	 */
	public static function handle_esp_sync_contact( $contact ) {
		$user = get_user_by( 'email', $contact['email'] );
		if ( ! $user ) {
			return $contact;
		}
		$contact['metadata']['network_registration_site'] = self::get_registration_site_meta( $user->ID );
		return $contact;
	}

	/**
	 * Get the registration site URL for a user.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return string The registration site URL.
	 */
	private static function get_registration_site_meta( $user_id ) {
		$remote_site = \get_user_meta( $user_id, User_Utils::USER_META_REMOTE_SITE, true );
		return \esc_url( ! empty( $remote_site ) ? $remote_site : \get_site_url() );
	}

	/**
	 * Filter the data for the event being triggered
	 *
	 * @param WP_User $user The newly created or existing user.
	 * @return void
	 */
	public static function new_reader_data_event( $user ) {
		if ( ! $user ) {
			return;
		}

		$registration_site_meta = self::get_registration_site_meta( $user->ID );
		return [
			'user_id'           => $user->ID,
			'registration_site' => $registration_site_meta,
		];
	}
}
