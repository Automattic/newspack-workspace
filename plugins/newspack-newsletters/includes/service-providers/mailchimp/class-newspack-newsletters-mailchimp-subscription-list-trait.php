<?php
/**
 * Service Provider: Mailchimp Subscription List Trait
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Extend the Subscription_List object with Mailchimp specific functionality.
 *
 * Since Mailchimp also offers Tags and Groups as Subscription Lists, some information is stored in the Public ID.
 *
 * This trait provides methods to extract the list type, the list ID and the Audience ID from the Public ID.
 *
 * All methods should be prefixed with `mailchimp_`.
 */
trait Newspack_Newsletters_Mailchimp_Subscription_List_Trait {

	/**
	 * Creates a list Public ID based on the type, the ID and the list (Audience) ID
	 *
	 * In Mailchimp, we offer both Audiences, Groups, and Tags as Subscription Lists. We modify the group and tag IDs so we can differentiate them from the Audiences IDs.
	 *
	 * Also, when working with groups or tags, we need to know the list ID, so we add it to the ID.
	 *
	 * @param string $item_id The item ID.
	 * @param string $list_id The List/Audience ID.
	 * @param string $type 'group' or 'tag'.
	 * @return string
	 */
	public static function mailchimp_generate_public_id( $item_id, $list_id, $type = 'group' ) {
		return $type . '-' . $item_id . '-' . $list_id;
	}

	/**
	 * Returns the type of the "sublist".
	 *
	 * If it's a remote list, it can be either a group or a tag. It returns false if it's not a sublist. (it's an Audience)
	 *
	 * @return string|false
	 */
	public function mailchimp_get_sublist_type() {
		if ( $this->is_local() ) {
			return 'group';
		}

		$extracted_ids = $this->mailchimp_get_sublist_details();
		if ( $extracted_ids ) {
			return $extracted_ids['type'];
		}

		return false;
	}

	/**
	 * Returns the Audience ID of the current list.
	 *
	 * @return string
	 */
	public function mailchimp_get_audience_id() {
		if ( $this->is_local() ) {
			$settings = $this->get_provider_settings( 'mailchimp' );
			if ( $settings ) {
				return $settings['list'];
			}
		}
		$extracted_ids = $this->mailchimp_get_sublist_details();
		if ( $extracted_ids ) {
			return $extracted_ids['list_id'];
		}
		return $this->get_public_id();
	}

	/**
	 * Returns the Group or Tag ID of the current list.
	 *
	 * @return string|false
	 */
	public function mailchimp_get_sublist_id() {
		if ( $this->is_local() ) {
			$settings = $this->get_provider_settings( 'mailchimp' );
			if ( $settings ) {
				return $settings['tag_id'];
			}
		}
		$extracted_ids = $this->mailchimp_get_sublist_details();
		if ( $extracted_ids ) {
			return $extracted_ids['id'];
		}
		return false;
	}

	/**
	 * Extract the group or tag + audience (list) ID from an ID created with self::mailchimp_generate_public_id
	 *
	 * @return array|false Array with the group/tag ID (key id), the Audience ID (key list_id), the list type (tag or group. key type) or false if the ID is not a group or tag list ID.
	 */
	protected function mailchimp_get_sublist_details() {
		$pattern = '/^(group|tag)-([^-]+)-([^-]+)$/';
		if ( preg_match( $pattern, $this->get_public_id(), $matches ) ) {
			$extracted_ids = [
				'id'      => $matches[2],
				'list_id' => $matches[3],
				'type'    => $matches[1],
			];

			return $extracted_ids;
		}
		return false;
	}
}
