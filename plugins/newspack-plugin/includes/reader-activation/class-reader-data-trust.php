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
	 * stop working. A test scans both plugins' sources for the literal keys passed
	 * to `store.set()` and `store.add()` and fails on one missing here; a key built
	 * at runtime or passed as a constant is not found by it.
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
		'newsletter_signup_client',
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
	 * A test writes every BROWSER_KEYS value and fails if a field outside this
	 * list changes.
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
	 * Per-request donor status by user ID, so a value update_item() refuses to
	 * store does not repeat the order lookup on every rule evaluation.
	 *
	 * @var array<int, bool|null>
	 */
	private static $donor_cache = [];

	/**
	 * Clear the per-request caches.
	 */
	public static function reset_cache(): void {
		self::$cache       = [];
		self::$donor_cache = [];
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
				$labels = Metadata::get_keys();
				$keys   = [];
				foreach ( self::get_stored_incoming_fields() as $integration_id => $fields ) {
					$integration = Integrations::get_integration( $integration_id );
					if ( ! $integration ) {
						continue;
					}
					$sent_as  = [];
					$perstags = [];
					foreach ( array_intersect( $integration->get_enabled_outgoing_fields_keys(), self::BROWSER_FED_OUTGOING_FIELDS ) as $raw_key ) {
						$sent_name  = $integration->get_metadata_prefix() . $labels[ $raw_key ];
						$sent_as[]  = $sent_name;
						$perstags[] = strtoupper( str_replace( '-', '_', sanitize_title( $sent_name ) ) );
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
	 * Whether reader-data rules may rely on the key's server-written value.
	 *
	 * @param string $key Reader data key.
	 *
	 * @return bool
	 */
	public static function is_trusted_key( string $key ): bool {
		return in_array( $key, self::get_trusted_keys(), true );
	}

	/**
	 * The stored value of a trusted key, if the server wrote it.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Reader data key.
	 *
	 * @return string|null The raw stored value, or null when the key is not
	 *                     trusted, is unset, or holds a value the server did not write.
	 */
	public static function get_trusted_value( int $user_id, string $key ): ?string {
		if ( $user_id <= 0 || ! self::is_trusted_key( $key ) ) {
			return null;
		}
		$value  = Reader_Data::get_data( $user_id, $key );
		$is_set = is_string( $value ) && '' !== $value;
		if ( 'is_donor' === $key && ( ! $is_set || ! self::has_valid_mark( $user_id, $key ) ) ) {
			// Donor status is derived from orders and subscriptions, so a value the
			// server never wrote can be recomputed from them instead of dropped.
			$computed = self::compute_is_donor( $user_id );
			// A stored `true` can come from a source outside WooCommerce, such as an
			// ESP's donor field, so a lookup that finds no donation leaves it in place.
			if ( false === $computed && $is_set && true === json_decode( $value ) ) {
				return null;
			}
			if ( null !== $computed ) {
				Reader_Data::update_item( $user_id, $key, $computed );
				$value  = Reader_Data::get_data( $user_id, $key );
				$is_set = is_string( $value ) && '' !== $value;
			}
		}
		if ( ! $is_set ) {
			return null;
		}
		if ( ! self::has_valid_mark( $user_id, $key ) ) {
			return null;
		}
		return $value;
	}

	/**
	 * Whether the key holds a stored value that the server did not write.
	 *
	 * For such a value get_trusted_value() returns null, just as it does for an
	 * unset key. A rule that passes readers without a value must still fail for
	 * this one, so callers that need to tell the two apart ask here.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Reader data key.
	 *
	 * @return bool
	 */
	public static function has_unmarked_value( int $user_id, string $key ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$value = Reader_Data::get_data( $user_id, $key );
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}
		return ! self::has_valid_mark( $user_id, $key );
	}

	/**
	 * Whether the user is a donor, according to their orders and subscriptions.
	 *
	 * A recurring donor counts only through an active subscription, so a former
	 * recurring donor (whose parent, renewal, switch and resubscribe orders
	 * remain) is not a donor. Only one-time donation orders count.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return bool|null Null when the platform does not track donors server-side,
	 *                   or when the order lookup is unavailable and nothing could be checked.
	 */
	public static function compute_is_donor( int $user_id ): ?bool {
		if ( $user_id <= 0 || ! Donations::has_server_side_donor_tracking() ) {
			return null;
		}
		if ( ! array_key_exists( $user_id, self::$donor_cache ) ) {
			self::$donor_cache[ $user_id ] = self::lookup_is_donor( $user_id );
		}
		return self::$donor_cache[ $user_id ];
	}

	/**
	 * Look up the user's donor status, uncached.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return bool|null Null when the order lookup is unavailable.
	 */
	private static function lookup_is_donor( int $user_id ): ?bool {
		/**
		 * Filters the computed donor status. A boolean replaces the WooCommerce
		 * lookup; any other return value is ignored.
		 *
		 * @param bool|null $is_donor Donor status, or null to compute it.
		 * @param int       $user_id  User ID.
		 */
		$filtered = apply_filters( 'newspack_reader_data_compute_is_donor', null, $user_id );
		if ( is_bool( $filtered ) ) {
			return $filtered;
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}

		if ( function_exists( 'wcs_get_users_subscriptions' ) ) {
			foreach ( \wcs_get_users_subscriptions( $user_id ) as $subscription ) {
				if ( ! $subscription->has_status( WooCommerce_Connection::ACTIVE_SUBSCRIPTION_STATUSES ) ) {
					continue;
				}
				foreach ( $subscription->get_items() as $item ) {
					if ( Donations::is_donation_product( $item->get_product_id() ) ) {
						return true;
					}
				}
			}
		}

		$orders = \wc_get_orders(
			[
				'customer_id' => $user_id,
				'status'      => WooCommerce_Connection::ACTIVE_ORDER_STATUSES,
				'limit'       => -1,
				'return'      => 'objects',
			]
		);
		foreach ( $orders as $order ) {
			if ( ! Donations::is_donation_order( $order ) ) {
				continue;
			}
			if ( function_exists( 'wcs_order_contains_subscription' ) && \wcs_order_contains_subscription( $order, 'any' ) ) {
				continue;
			}
			return true;
		}

		return false;
	}

	/**
	 * Whether a stored value equals a reader-data rule value.
	 *
	 * Values are stored JSON-encoded, so the stored side is decoded and compared by
	 * type: strings exactly, whether the rule value is bare or JSON-quoted,
	 * booleans as `true`/`false`, numbers numerically.
	 * Arrays, objects and null never match. A stored value that is not JSON is
	 * compared as the raw string. Callers trim the rule value.
	 *
	 * @param string $stored     Raw stored value.
	 * @param string $rule_value Rule value.
	 *
	 * @return bool
	 */
	public static function values_match( string $stored, string $rule_value ): bool {
		$decoded = json_decode( $stored, true );
		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			return $stored === $rule_value;
		}
		if ( is_string( $decoded ) ) {
			if ( $decoded === $rule_value ) {
				return true;
			}
			// Rules written before string matching hold the stored form, quotes
			// included (`plan="premium"`), and must keep matching.
			$decoded_rule = json_decode( $rule_value, true );
			return is_string( $decoded_rule ) && $decoded === $decoded_rule;
		}
		if ( is_bool( $decoded ) ) {
			return ( $decoded ? 'true' : 'false' ) === $rule_value;
		}
		if ( is_int( $decoded ) || is_float( $decoded ) ) {
			return is_numeric( $rule_value ) && (float) $decoded === (float) $rule_value;
		}
		return false;
	}

	/**
	 * Read the raw value as stored in user meta.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Reader data key.
	 *
	 * @return string
	 */
	private static function get_stored_value( int $user_id, string $key ): string {
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
