<?php
/**
 * Newsletters contact metadata.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Sync\Contact_Metadata;

use Newspack\Reader_Data;
use Newspack\Reader_Activation\Sync\Contact_Metadata;
use Newspack\Reader_Activation\Sync\Legacy_Metadata;
use Newspack\Reader_Activation\Sync\Metadata;

defined( 'ABSPATH' ) || exit;

/**
 * The reader's newsletter selection, built from the lists stored in reader data.
 *
 * Reader data is the source rather than a live ESP lookup so the field can be
 * computed on every sync path (shutdown flush, retries, CLI, cron) without an
 * API call per contact, and so it agrees with the lists Campaigns segments on.
 * The stored lists are written by the newsletter_subscribed and
 * newsletter_updated data events and refreshed from the ESP on login (#2619).
 */
class Newsletters extends Contact_Metadata {
	/**
	 * Whether this metadata class is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'Newspack_Newsletters_Subscription' );
	}

	/**
	 * Get the section name for the settings UI.
	 *
	 * @return string
	 */
	public static function get_section_name() {
		return __( 'Newsletters', 'newspack-plugin' );
	}

	/**
	 * Get the fields this class computes.
	 *
	 * The raw key and label match the legacy field map, so existing outbound
	 * field selections and CLI field names keep resolving to the same ESP field.
	 *
	 * @return array
	 */
	public static function get_fields() {
		return [
			'newsletter_selection' => 'Newsletter Selection',
		];
	}

	/**
	 * Get the metadata.
	 *
	 * The field is omitted, rather than sent empty, when the reader has no
	 * stored lists or the lists config cannot be read: an unknown selection must
	 * not overwrite a value the ESP already holds. A stored empty list is a real
	 * state (unsubscribed from everything) and sends an empty string.
	 *
	 * @return array
	 */
	public function get_metadata() {
		if ( ! $this->user ) {
			return [];
		}

		$stored = Reader_Data::get_data( $this->user->ID, 'newsletter_subscribed_lists' );
		if ( false === $stored ) {
			return [];
		}
		$ids = is_string( $stored ) ? json_decode( $stored, true ) : $stored;
		if ( ! is_array( $ids ) ) {
			return [];
		}

		$lists = \Newspack_Newsletters_Subscription::get_lists();
		if ( \is_wp_error( $lists ) || ! is_array( $lists ) ) {
			return [];
		}

		// Names follow the lists config order so the value is stable regardless
		// of the order in which the reader subscribed.
		$names = [];
		foreach ( $lists as $list ) {
			if ( isset( $list['id'], $list['name'] ) && in_array( (string) $list['id'], array_map( 'strval', $ids ), true ) ) {
				$names[] = $list['name'];
			}
		}

		$metadata = [ 'newsletter_selection' => implode( ', ', $names ) ];

		// In legacy mode the sync path does not normalize the merged contact, so
		// the key must already carry the metadata prefix (see Content_Gate).
		if ( 'legacy' === Metadata::get_version() ) {
			$normalized = Legacy_Metadata::normalize_contact_data( [ 'metadata' => $metadata ] );
			return $normalized['metadata'] ?? [];
		}
		return $metadata;
	}
}
