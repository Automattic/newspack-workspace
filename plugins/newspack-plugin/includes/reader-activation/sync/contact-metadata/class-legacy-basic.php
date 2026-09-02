<?php
/**
 * Legacy basic contact metadata fields.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Sync\Contact_Metadata;

use Newspack\Logger;
use Newspack\Reader_Data;
use Newspack\Reader_Activation\Sync\Contact_Metadata;
use Newspack\Reader_Activation\Sync\Legacy_Metadata;
use Newspack\Reader_Activation\Sync\Metadata;
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

		// Added before normalization so the field follows the outgoing-field
		// selection and prefix rules like every other legacy field.
		$newsletter_selection = $this->get_newsletter_selection();
		if ( null !== $newsletter_selection ) {
			$contact['metadata']['newsletter_selection'] = $newsletter_selection;
		}

		$contact = Legacy_Metadata::normalize_contact_data( $contact );

		return $contact['metadata'] ?? [];
	}

	/**
	 * The reader's newsletter selection: the names of the lists stored in
	 * reader data, joined with ", ".
	 *
	 * Reader data is the source rather than a live ESP lookup so the field is
	 * computed wherever legacy metadata is built (shutdown flush, retries, CLI,
	 * cron; like every legacy field this needs a WooCommerce customer) from
	 * the cached lists config instead of a per-contact API call, and agrees
	 * with the lists Campaigns segments on. The stored lists are written by the
	 * newsletter_subscribed and newsletter_updated data events and refreshed
	 * from the ESP on login (Automattic/newspack-plugin#2619).
	 *
	 * Null omits the field: a site that does not send it, a reader with no
	 * stored lists or a stored value that is not a plain list, or an
	 * unreadable lists config all mean an unknown selection that must not
	 * overwrite a value the ESP already holds. A stored empty list is a real
	 * state (unsubscribed from everything) and yields an empty string.
	 *
	 * @return string|null
	 */
	private function get_newsletter_selection(): ?string {
		if ( ! $this->user || ! class_exists( 'Newspack_Newsletters_Subscription' ) ) {
			return null;
		}

		// The same selection normalize_contact_data() filters by, checked first
		// so a disabled field does not cost a lists lookup per contact.
		if ( ! in_array( 'newsletter_selection', Metadata::get_raw_keys(), true ) ) {
			return null;
		}

		// Log why a selection is omitted or empty: once a blank value reaches the
		// ESP, neither side keeps what it replaced, so this is the only record.
		$ids = Reader_Data::get_newsletter_subscribed_lists( $this->user->ID );
		if ( null === $ids ) {
			if ( false !== Reader_Data::get_data( $this->user->ID, 'newsletter_subscribed_lists' ) ) {
				Logger::log( sprintf( 'Newsletter Selection omitted for user %d: the stored lists are not a plain list.', $this->user->ID ) );
			}
			return null;
		}

		$lists = \Newspack_Newsletters_Subscription::get_lists();
		if ( \is_wp_error( $lists ) || ! is_array( $lists ) ) {
			Logger::log( sprintf( 'Newsletter Selection omitted for user %d: the lists config is unreadable.', $this->user->ID ) );
			return null;
		}

		// Names follow the lists config order so the value is stable regardless
		// of the order in which the reader subscribed.
		$names = [];
		foreach ( $lists as $list ) {
			if ( isset( $list['id'], $list['name'] ) && in_array( (string) $list['id'], $ids, true ) ) {
				$names[] = $list['name'];
			}
		}

		$selection = implode( ', ', $names );
		if ( '' === $selection ) {
			Logger::log( sprintf( 'Newsletter Selection is empty for user %d (stored lists: %s).', $this->user->ID, wp_json_encode( $ids ) ) );
		}

		return $selection;
	}
}
