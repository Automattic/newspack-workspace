<?php
/**
 * Reader Data Trust: tracks which stored reader data values the server wrote.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Reader_Activation\Integration;
use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Sync\Metadata;

defined( 'ABSPATH' ) || exit;

/**
 * Reader Data Trust Class.
 */
final class Reader_Data_Trust {

	const MARK_META_PREFIX = 'newspack_reader_data_mark_';

	/**
	 * Keys the browser writes through the reader data store (`store.set()` and
	 * `store.add()` in newspack-plugin and newspack-popups).
	 *
	 * Incoming field keys are the provider's own field names and share the store's
	 * namespace. One that equals a key below cannot be made read-only, because the
	 * browser store refuses writes to read-only keys and the script writing it would
	 * stop working. A test scans both plugins' sources to keep this list complete.
	 *
	 * `is_donor` stays listed although it is read-only where the server tracks
	 * donors: elsewhere the browser still writes it.
	 */
	const BROWSER_KEYS = [
		'activity',
		'article_view_per_month',
		'article_view_per_week',
		'articles_read',
		'favorite_categories',
		'first_visit_date',
		'is_donor',
		'last_active',
		'matched_segments',
		'pageviews',
		'paywall_hits',
		'pending_checkout',
		'reader',
		'referrer',
		'utm_source',
	];

	/**
	 * Outgoing metadata fields whose values are read from browser-written reader
	 * data (see Sync\Contact_Metadata\Engagement::get_metadata()), as raw keys.
	 */
	const BROWSER_FED_OUTGOING_FIELDS = [
		'First_Visit_Date',
		'Last_Active',
		'Paywall_Hits',
		'Favorite_Categories',
	];

	/**
	 * Per-request cache of computed key sets.
	 *
	 * @var array<string, string[]>
	 */
	private static $cache = [];

	/**
	 * Clear the per-request key cache.
	 */
	public static function reset_cache(): void {
		self::$cache = [];
	}

	/**
	 * Enabled incoming field keys of every active integration, as the stored
	 * option holds them.
	 *
	 * Reads the option rather than Integration::get_enabled_incoming_fields(),
	 * which can fetch the provider's live field list and must not run on every page
	 * load. The keys are the same: that method builds one field per stored key.
	 *
	 * @return array<string, array<string, array>> Integration ID => ( key => raw field data ).
	 */
	private static function get_stored_incoming_fields(): array {
		$fields = [];
		foreach ( Integrations::get_active_integrations() as $integration ) {
			$stored = \get_option( Integration::INCOMING_FIELDS_OPTION_PREFIX . $integration->get_id(), [] );
			if ( ! is_array( $stored ) ) {
				continue;
			}
			foreach ( $stored as $key => $raw_data ) {
				if ( is_string( $key ) && '' !== $key ) {
					$fields[ $integration->get_id() ][ $key ] = is_array( $raw_data ) ? $raw_data : [];
				}
			}
		}
		return $fields;
	}

	/**
	 * Compute a key set once per request.
	 *
	 * Nothing is cached before integrations register, so an early caller cannot
	 * pin an empty set for the rest of the request.
	 *
	 * @param string   $name    Cache slot.
	 * @param callable $compute Returns the key set.
	 *
	 * @return string[]
	 */
	private static function cached( string $name, callable $compute ): array {
		if ( isset( self::$cache[ $name ] ) ) {
			return self::$cache[ $name ];
		}
		$keys = array_values( array_unique( $compute() ) );
		if ( Integrations::are_integrations_registered() ) {
			self::$cache[ $name ] = $keys;
		}
		return $keys;
	}

	/**
	 * Enabled incoming field keys across active integrations, minus keys the
	 * browser writes.
	 *
	 * @return string[]
	 */
	public static function get_incoming_field_keys(): array {
		return self::cached(
			'incoming',
			function () {
				$keys = [];
				foreach ( self::get_stored_incoming_fields() as $fields ) {
					$keys = array_merge( $keys, array_keys( $fields ) );
				}
				return array_diff( $keys, self::BROWSER_KEYS );
			}
		);
	}

	/**
	 * Incoming field keys that are the ESP's copy of a browser-fed outgoing field.
	 *
	 * An integration sends each enabled outgoing field under `<prefix><label>`
	 * (Integration::prepare_contact()). Newspack Newsletters creates the provider
	 * field with that name: Mailchimp as the merge field's name, with a tag the
	 * provider assigns; ActiveCampaign as the title, with a perstag generated from
	 * it. The incoming field key is the provider's identifier (tag or perstag) and
	 * its stored `name` is the provider's display name, so the same field can come
	 * back on contact pull. Such a field is matched by its name, by the perstag
	 * Newsletters generates, or by a key equal to the sent name.
	 *
	 * A pulled value of such a field is the reader's own browser data with a server
	 * mark, so these keys stay read-only but are not trusted.
	 *
	 * @return string[]
	 */
	public static function get_round_trip_keys(): array {
		return self::cached(
			'round_trip',
			function () {
				$labels = Metadata::get_all_fields();
				$keys   = [];
				foreach ( self::get_stored_incoming_fields() as $integration_id => $fields ) {
					$integration = Integrations::get_integration( $integration_id );
					if ( ! $integration ) {
						continue;
					}
					$enabled  = $integration->get_enabled_outgoing_fields();
					$sent_as  = [];
					$perstags = [];
					foreach ( self::BROWSER_FED_OUTGOING_FIELDS as $raw_key ) {
						if ( isset( $labels[ $raw_key ] ) && in_array( $labels[ $raw_key ], $enabled, true ) ) {
							$sent_name  = $integration->get_metadata_prefix() . $labels[ $raw_key ];
							$sent_as[]  = $sent_name;
							$perstags[] = strtoupper( str_replace( '-', '_', sanitize_title( $sent_name ) ) );
						}
					}
					if ( empty( $sent_as ) ) {
						continue;
					}
					foreach ( $fields as $key => $raw_data ) {
						$name = isset( $raw_data['name'] ) && is_string( $raw_data['name'] ) ? $raw_data['name'] : null;
						if ( in_array( $key, $sent_as, true ) || in_array( $key, $perstags, true ) || in_array( $name, $sent_as, true ) ) {
							$keys[] = $key;
						}
					}
				}
				return $keys;
			}
		);
	}

	/**
	 * Keys whose server-written values reader-data rules may rely on.
	 *
	 * @return string[]
	 */
	public static function get_trusted_keys(): array {
		return array_values( array_diff( Reader_Data::get_read_only_keys(), self::get_round_trip_keys() ) );
	}

	/**
	 * Read the raw value as stored in user meta.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Reader data key.
	 *
	 * @return string
	 */
	private static function get_stored_value( $user_id, $key ) {
		$value = \get_user_meta( $user_id, Reader_Data::get_meta_key_name( $key ), true );
		return is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
	}

	/**
	 * Record that the server wrote the key's current stored value.
	 *
	 * The mark is a hash of the raw stored value, so any later change to that
	 * value that does not go through the server voids it.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Reader data key.
	 */
	public static function set_mark( int $user_id, string $key ): void {
		\update_user_meta( $user_id, self::MARK_META_PREFIX . $key, hash( 'sha256', self::get_stored_value( $user_id, $key ) ) );
	}

	/**
	 * Remove the server mark for a key.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Reader data key.
	 */
	public static function clear_mark( int $user_id, string $key ): void {
		\delete_user_meta( $user_id, self::MARK_META_PREFIX . $key );
	}

	/**
	 * Whether the key's current stored value is the one the server wrote.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Reader data key.
	 *
	 * @return bool
	 */
	public static function has_valid_mark( int $user_id, string $key ): bool {
		$mark = \get_user_meta( $user_id, self::MARK_META_PREFIX . $key, true );
		if ( ! is_string( $mark ) || '' === $mark ) {
			return false;
		}
		return hash_equals( $mark, hash( 'sha256', self::get_stored_value( $user_id, $key ) ) );
	}
}
