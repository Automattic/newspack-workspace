<?php
/**
 * Service Provider: Mailchimp Notes Implementation
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

use Newspack_Newsletters_Mailchimp_Api as Mailchimp;
use Newspack\Newsletters\Subscription_List;

/**
 * This class adds logging to Mailchimp contacts using Mailchimp Notes.
 */
class Newspack_Newsletters_Mailchimp_Notes {

	/**
	 * Initializes the hooks.
	 *
	 * @return void
	 */
	public static function init() {

		// Disabling this feature until we can work on better and more helpful logging for the end user.

		// phpcs:ignore
		// add_action( 'newspack_newsletters_upsert', array( __CLASS__, 'handle_upsert' ), 10, 6 );

		// phpcs:ignore
		// add_action( 'newspack_newsletters_update_contact_lists', array( __CLASS__, 'handle_update_lists' ), 10, 6 );
	}

	/**
	 * Add a note to a contact in Mailchimp, for Audience list they are a part of.
	 *
	 * @param string $email The contact email.
	 * @param array  $lists An array of lists IDs (as they are used in our forms. aka public_id). A list can be an Audience, a tag, a group or a local list.
	 * @param string $note The note to add.
	 * @return void
	 */
	private static function add_note( $email, $lists, $note ) {
		$audience_ids = self::extract_audience_ids( $lists );
		if ( empty( $audience_ids ) ) {
			return;
		}
		try {
			$mc = Newspack_Newsletters_Mailchimp::instance();
			$api      = new Mailchimp( $mc->api_key() );
			foreach ( $audience_ids as $audience_id ) {
				$subscriber_hash = Mailchimp::subscriberHash( $email );
				$api->post(
					sprintf( 'lists/%s/members/%s/notes', $audience_id, $subscriber_hash ),
					[
						'note' => $note,
					]
				);
			}
		} catch ( Exception $e ) {
			Newspack_Newsletters_Logger::log( 'Error adding note to Mailchimp contact: ' . $e->getMessage() );
		}
	}

	/**
	 * Extracts the Audience IDs from the list public IDs.
	 *
	 * Given a list of lists, we will parse in which audience they belong.
	 *
	 * @param array $lists An array of lists IDs (as they are used in our forms. aka public_id). A list can be an Audience, a tag, a group or a local list.
	 * @return array An array of Audience IDs.
	 */
	private static function extract_audience_ids( $lists ) {
		$mc = Newspack_Newsletters_Mailchimp::instance();
		$audience_ids = [];
		foreach ( $lists as $list ) {
			$list_obj = Subscription_List::from_public_id( $list );
			if ( ! $list_obj ) {
				continue;
			}
			$audience_ids[] = $list_obj->mailchimp_get_audience_id();
		}
		return $audience_ids;
	}

	/**
	 * Handles the upsert of a contact.
	 *
	 * @param string         $provider The provider name.
	 * @param array          $contact  {
	 *    Contact information.
	 *
	 *    @type string   $email    Contact email address.
	 *    @type string   $name     Contact name. Optional.
	 *    @type string[] $metadata Contact additional metadata. Optional.
	 * }
	 * @param string[]|false $lists    Array of list IDs to subscribe the contact to.
	 * @param array|WP_Error $result   Array with data if the contact was added or error if failed.
	 * @param bool           $is_updating Whether the contact is being updated. If false, the contact is being created.
	 * @param string         $context  Context of the update for logging purposes.
	 * @return void
	 */
	public static function handle_upsert( $provider, $contact, $lists, $result, $is_updating, $context ) {
		if ( 'mailchimp' !== $provider ) {
			return;
		}

		$lists_string = implode( ', ', $lists );
		$message = sprintf(
			/* translators: 1: email address, 2: list IDs */
			__( 'Contact updated by Newspack from site %1$s. Context: %2$s. Lists added: %3$s', 'newspack-newsletters' ),
			get_site_url(),
			$context,
			$lists_string
		);

		self::add_note( $contact['email'], $lists, $message );
	}

	/**
	 * Handles the update of lists for a contact.
	 *
	 * Note that we will add a note even to the lists (audiences) that the contact is being removed from.
	 *
	 * @param string        $provider        The provider name.
	 * @param string        $email           Contact email address.
	 * @param string[]      $lists_to_add    Array of list IDs to subscribe the contact to.
	 * @param string[]      $lists_to_remove Array of list IDs to remove the contact from.
	 * @param bool|WP_Error $result          True if the contact was updated or error if failed.
	 * @param string        $context         Context of the update for logging purposes.
	 * @return void
	 */
	public static function handle_update_lists( $provider, $email, $lists_to_add, $lists_to_remove, $result, $context ) {
		if ( 'mailchimp' !== $provider ) {
			return;
		}

		$lists_to_add_string = implode( ', ', $lists_to_add );
		$lists_to_remove_string = implode( ', ', $lists_to_remove );

		$message = sprintf(
			/* translators: 1: email address, 2: lists added, 3: lists removed */
			__( 'Contact updated by Newspack. Context: %1$s. Lists added: %2$s. Lists removed: %3$s', 'newspack-newsletters' ),
			$context,
			$lists_to_add_string ? $lists_to_add_string : 'none',
			$lists_to_remove_string ? $lists_to_remove_string : 'none'
		);

		self::add_note( $email, array_merge( $lists_to_add, $lists_to_remove ), $message );
	}
}

Newspack_Newsletters_Mailchimp_Notes::init();
