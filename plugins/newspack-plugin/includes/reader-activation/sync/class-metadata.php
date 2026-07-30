<?php
/**
 * Reader Activation Sync Metadata.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Sync;

use Newspack\Donations;
use Newspack\Logger;
use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Metadata Class.
 */
class Metadata {

	const DATE_FORMAT   = 'Y-m-d H:i:s';
	const PREFIX        = 'NP_';
	const PREFIX_OPTION = '_newspack_metadata_prefix';

	/**
	 * The option name for choosing which metadata fields to sync.
	 *
	 * @var string
	 */
	const FIELDS_OPTION = '_newspack_metadata_fields';

	/**
	 * Get the metadata classes to be used for syncing contact metadata to the ESP.
	 *
	 * All schema versions' classes are always registered; per-integration
	 * enabled field ids decide what actually syncs.
	 *
	 * @return array List of metadata classes.
	 */
	protected static function get_metadata_classes() {
		$classes = [
			'Legacy_Basic',
			'Legacy_Payment',
			'Identity',
			'Registration',
			'Engagement',
			'Subscription',
			'Donation',
			'Content_Gate',
		];

		$classnames = [];
		foreach ( $classes as $class ) {
			$classname = __NAMESPACE__ . '\\Contact_Metadata\\' . $class;
			if ( class_exists( $classname ) ) {
				$classnames[] = $classname;
			}
		}
		return $classnames;
	}

	/**
	 * Get the metadata classes scoped to the site's schema origin.
	 *
	 * Phase-1 UI freeze: the existing field-selection UI keeps rendering
	 * the origin version's list until the per-field UI ships.
	 *
	 * @return array List of metadata classes.
	 */
	protected static function get_origin_metadata_classes() {
		$origin = Field_Registry::get_schema_origin();
		$v1     = [ Contact_Metadata\Legacy_Basic::class, Contact_Metadata\Legacy_Payment::class, Contact_Metadata\Content_Gate::class ];
		$v2     = [ Contact_Metadata\Identity::class, Contact_Metadata\Registration::class, Contact_Metadata\Engagement::class, Contact_Metadata\Subscription::class, Contact_Metadata\Donation::class, Contact_Metadata\Content_Gate::class ];
		return array_values( array_filter( Field_Registry::VERSION_V1 === $origin ? $v1 : $v2, 'class_exists' ) );
	}

	/**
	 * Get the metadata keys map for Reader Activation.
	 *
	 * @return array List of fields.
	 */
	public static function get_keys() {
		return self::get_all_fields( true );
	}

	/**
	 * Fetch the prefix for synced metadata fields.
	 * Default is NP_ but it can be configured in the Reader Activation settings page.
	 *
	 * This method is deprecated. Now, each integration has its own metadata prefix, which can be retrieved with Integration::get_metadata_prefix().
	 * As a fallback, this method returns the metadata prefix for the ESP Integration.
	 *
	 * @deprecated Use Integration::get_metadata_prefix() instead.
	 *
	 * @return string
	 */
	public static function get_prefix() {
		$esp_integration = Integrations::get_integration( 'esp' );
		if ( $esp_integration ) {
			$prefix = $esp_integration->get_metadata_prefix();
			if ( ! empty( $prefix ) ) {
				/** This filter is documented below. */
				return apply_filters( 'newspack_ras_metadata_prefix', $prefix );
			}
		}

		// Fallback for edge case where integration isn't registered yet (before init priority 5).
		$prefix = \get_option( self::PREFIX_OPTION, self::PREFIX );

		// Guard against empty strings and falsy values.
		if ( empty( $prefix ) ) {
			return self::PREFIX;
		}

		/**
		 * Filters the string used to prefix custom fields synced.
		 *
		 * @param string $prefix Prefix to prepend the field name.
		 */
		return apply_filters( 'newspack_ras_metadata_prefix', $prefix );
	}

	/**
	 * Update the prefix for synced metadata fields.
	 *
	 * @param string $prefix Value to set.
	 *
	 * @return boolean True if updated, false otherwise.
	 */
	public static function update_prefix( $prefix ) {
		$esp_integration = Integrations::get_integration( 'esp' );
		return $esp_integration ? $esp_integration->update_metadata_prefix( $prefix ) : false;
	}

	/**
	 * Get the list of possible fields to be synced.
	 *
	 * @return string[] List of fields.
	 */
	public static function get_default_fields() {
		return array_values( array_unique( array_values( self::get_keys() ) ) );
	}

	/**
	 * Get payment-related metadata fields (v1 map).
	 *
	 * Used by the WooCommerce sync class when clearing payment fields.
	 *
	 * @return array List of fields.
	 */
	public static function get_payment_fields() {
		return Contact_Metadata\Legacy_Payment::get_fields();
	}

	/**
	 * Get the UTM key from a raw or prefixed key.
	 *
	 * Port of the legacy helper: validates the UTM family against the
	 * fields enabled for the ESP integration and returns the prefixed,
	 * suffixed ESP name (e.g. "NP_Signup UTM: source"), or false.
	 *
	 * @param string $key Key to check.
	 *
	 * @return string|false
	 */
	public static function get_utm_key( $key ) {
		$utm_keys = [ 'signup_page_utm', 'payment_page_utm' ];
		$raw_keys = self::get_raw_keys();
		foreach ( $utm_keys as $utm_key ) {
			if ( ! in_array( $utm_key, $raw_keys, true ) ) {
				continue;
			}
			$prefixed_key = self::get_key( $utm_key );
			if ( 0 === strpos( $key, $utm_key ) ) {
				$suffix = str_replace( $utm_key . '_', '', $key );
				return ! empty( trim( $suffix ) ) && $suffix !== $key ? $prefixed_key . $suffix : false;
			}
			if ( 0 === strpos( $key, $prefixed_key ) && $key !== $prefixed_key ) {
				return $key;
			}
		}
		return false;
	}

	/**
	 * Get the list of fields to be synced.
	 *
	 * This method is deprecated. Now, each integration has its own set of enabled fields, which can be retrieved with Integration::get_enabled_outgoing_fields().
	 * As a fallback, this method returns the fields enabled for the ESP Integration.
	 *
	 * @deprecated Use Integration::get_enabled_outgoing_fields() instead.
	 * @return string[] List of fields to be synced.
	 */
	public static function get_fields() {
		$esp_integration = Integrations::get_integration( 'esp' );
		return $esp_integration ? $esp_integration->get_enabled_outgoing_fields() : [];
	}

	/**
	 * Get enabled fields which match provided keys.
	 * Will return key-value pairs of enabled fields which match the keys provided.
	 *
	 * This method is deprecated. Now, each integration has its own set of enabled fields.
	 * As a fallback, this method delegates to the ESP Integration.
	 *
	 * @deprecated Use Integration::filter_enabled_outgoing_fields() instead.
	 * @param string[] $keys Array of keys to match.
	 */
	public static function filter_enabled_fields( $keys ) {
		$esp_integration = Integrations::get_integration( 'esp' );
		return $esp_integration ? $esp_integration->filter_enabled_outgoing_fields( $keys ) : [];
	}

	/**
	 * Update the list of fields to be synced.
	 *
	 * This method is deprecated. Now, each integration has its own set of enabled fields.
	 * As a fallback, this method will update the fields enabled for the ESP Integration.
	 *
	 * @param array $fields List of fields to sync.
	 *
	 * @deprecated Use Integration::update_enabled_outgoing_fields() instead.
	 * @return boolean True if updated, false otherwise.
	 */
	public static function update_fields( $fields ) {
		$esp_integration = Integrations::get_integration( 'esp' );
		return $esp_integration ? $esp_integration->update_enabled_outgoing_fields( $fields ) : false;
	}

	/**
	 * Get the "raw" unprefixed metadata keys. Only return fields selected to sync.
	 *
	 * This method is deprecated. Now, each integration has its own set of enabled fields.
	 * As a fallback, this method delegates to the ESP Integration.
	 *
	 * @deprecated Use Integration::get_enabled_outgoing_fields_keys() instead.
	 * @return string[] List of raw metadata keys.
	 */
	public static function get_raw_keys() {
		$esp_integration = Integrations::get_integration( 'esp' );
		return $esp_integration ? $esp_integration->get_enabled_outgoing_fields_keys() : [];
	}

	/**
	 * Get the "prefixed" metadata keys. Only return fields selected to sync.
	 *
	 * This method is deprecated. Now, each integration has its own set of enabled fields.
	 * As a fallback, this method delegates to the ESP Integration.
	 *
	 * @deprecated Use Integration::get_enabled_outgoing_fields_keys() instead.
	 * @return string[] List of prefixed metadata keys.
	 */
	public static function get_prefixed_keys() {
		$esp_integration = Integrations::get_integration( 'esp' );
		return $esp_integration ? $esp_integration->get_enabled_outgoing_fields_keys( true ) : [];
	}

	/**
	 * Get all "prefixed" metadata keys.
	 *
	 * @return string[] List of prefixed metadata keys.
	 */
	public static function get_all_prefixed_keys() {
		$prefixed_keys = [];

		foreach ( self::get_keys() as $raw_key => $field_name ) {
			$prefixed_keys[] = self::get_key( $raw_key );
		}

		return array_unique( $prefixed_keys );
	}

	/**
	 * Given a field name, prepend it with the metadata field prefix.
	 *
	 * @param string $key Metadata field to fetch.
	 *
	 * @return string Prefixed field name.
	 */
	public static function get_key( $key ) {
		if ( ! isset( self::get_keys()[ $key ] ) ) {
			return false;
		}

		$prefix = self::get_prefix();
		$name   = self::get_keys()[ $key ];
		$key    = $prefix . $name;

		/**
		 * Filters the full, prefixed field name of each custom field synced to the ESP.
		 *
		 * @param string $key Full, prefixed key.
		 * @param string $prefix The prefix part of the key.
		 * @param string $name The unprefixed part of the key.
		 */
		return apply_filters( 'newspack_ras_metadata_key', $key, $prefix, $name );
	}

	/**
	 * Get the list of possible fields to be synced, grouped by section.
	 *
	 * Returns an array of groups, each with a 'section' label and 'fields' array.
	 * Only includes non-legacy classes with a section name. Fields are intersected
	 * with the filtered available fields list so extensions using the
	 * `newspack_ras_metadata_keys` filter are respected. Fields added by the filter
	 * that don't belong to any class are collected in an "Additional" group.
	 *
	 * @return array<int, array{section: string, fields: list<string>}> List of
	 *   groups, each with a non-empty section label and an ordered list of field
	 *   names. May be filtered by `newspack_ras_grouped_metadata_fields`.
	 */
	public static function get_grouped_default_fields(): array {
		$classes          = self::get_origin_metadata_classes();
		$available_fields = array_values( array_unique( array_values( self::get_all_fields( true ) ) ) );
		$groups           = [];
		$grouped_fields   = [];

		foreach ( $classes as $class ) {
			if ( $class::is_available() ) {
				$section = $class::get_section_name();
				if ( empty( $section ) ) {
					continue;
				}

				$fields = array_values( array_unique( array_values( $class::get_fields() ) ) );
				$fields = array_values( array_intersect( $fields, $available_fields ) );

				if ( empty( $fields ) ) {
					continue;
				}

				$groups[]       = [
					'section' => $section,
					'fields'  => $fields,
				];
				$grouped_fields = array_merge( $grouped_fields, $fields );
			}
		}

		$ungrouped_fields = array_values( array_diff( $available_fields, array_unique( $grouped_fields ) ) );
		if ( ! empty( $ungrouped_fields ) ) {
			$groups[] = [
				'section' => __( 'Additional', 'newspack-plugin' ),
				'fields'  => $ungrouped_fields,
			];
		}

		/**
		 * Filters the list of possible metadata fields to be synced, grouped by section.
		 *
		 * @param array[]  $groups           Array of [ 'section' => string, 'fields' => string[] ].
		 * @param string[] $available_fields Flat list of filtered available metadata field names.
		 */
		return \apply_filters( 'newspack_ras_grouped_metadata_fields', $groups, $available_fields );
	}

	/**
	 * Get all metadata fields.
	 *
	 * Scoped to the site's schema origin: this feeds the field-selection UI,
	 * get_key() and the partial-payload builders, which stay on the origin
	 * version's list until the per-field UI ships.
	 *
	 * @param boolean $only_available Whether to return only available fields or all fields.
	 * @return array List of fields.
	 */
	public static function get_all_fields( $only_available = false ) {
		$classes = self::get_origin_metadata_classes();
		$keys    = [];
		foreach ( $classes as $class ) {
			if ( ! $only_available || $class::is_available() ) {
				$fields = $class::get_fields();
				$keys = array_merge( $keys, $fields );
			}
		}
		/**
		 * Filters the list of key/value pairs for metadata fields to be synced to the connected ESP.
		 *
		 * @param array $keys The list of key/value pairs for metadata fields to be synced to the connected ESP.
		 * @param boolean $only_available Whether the list of fields is filtered to only available fields or not.
		 */
		return \apply_filters( 'newspack_ras_metadata_keys', $keys, $only_available );
	}



	/**
	 * Get a contact array with email and metadata for the given user, customer or order.
	 *
	 * @param \WP_User|\WC_Customer|\WC_Order|int $user_customer_or_order WP_User, WC_Customer, WC_Order object or ID.
	 *
	 * @return array Contact array with 'email' and 'metadata' keys.
	 */
	public static function get_contact_with_metadata( $user_customer_or_order ) {
		$core_contact = new Contact_Metadata\Core_Contact( $user_customer_or_order );
		// Deliberately the merged list: the contact carries raw keys from every
		// schema version, and each integration filters them in prepare_contact().
		$classes      = self::get_metadata_classes();
		$metadata     = [];

		foreach ( $classes as $class ) {
			if ( $class::is_available() ) {
				$instance = new $class( $user_customer_or_order );
				$metadata = array_merge( $metadata, $instance->get_metadata() );
			}
		}

		return self::normalize_contact_data(
			[
				'email'    => $core_contact->get_email(),
				'name'     => $core_contact->get_full_name(),
				'metadata' => $metadata,
			]
		);
	}

	/**
	 * Check if a metadata key exists in the given metadata.
	 *
	 * This method checks for both raw and prefixed keys.
	 *
	 * @param string $key      Metadata key to check.
	 * @param array  $metadata Metadata to check.
	 *
	 * @return boolean
	 */
	public static function has_key( $key, $metadata ) {
		return isset( $metadata[ $key ] ) || isset( $metadata[ self::get_key( $key ) ] );
	}

	/**
	 * Get a metadata key value from the given metadata.
	 *
	 * This method checks for both raw and prefixed keys.
	 *
	 * @param string $key      Metadata key to fetch.
	 * @param array  $metadata Metadata to fetch from.
	 *
	 * @return mixed|null Metadata value or null if not found.
	 */
	public static function get_key_value( $key, $metadata ) {
		if ( isset( $metadata[ $key ] ) ) {
			return $metadata[ $key ];
		}
		if ( isset( $metadata[ self::get_key( $key ) ] ) ) {
			return $metadata[ self::get_key( $key ) ];
		}
		return null;
	}

	/**
	 * Add user's registration-related data to the given metadata, as raw keys.
	 *
	 * Raw-key port of the legacy enrichment: values are looked up from user
	 * meta when absent, never overwritten, and no prefixing or filtering is
	 * performed here.
	 *
	 * @param array $metadata Metadata to add to.
	 *
	 * @return array Metadata with registration data added.
	 */
	public static function add_registration_data_raw( $metadata ) {
		$user = self::has_key( 'account', $metadata ) ? \get_user_by( 'id', self::get_key_value( 'account', $metadata ) ) : false;
		if ( ! $user ) {
			return $metadata;
		}

		$registration_method = self::has_key( 'registration_method', $metadata ) ? self::get_key_value( 'registration_method', $metadata ) : \get_user_meta( $user->ID, Reader_Activation::REGISTRATION_METHOD, true );
		if ( ! empty( $registration_method ) ) {
			$metadata['registration_method'] = $registration_method;
		}

		$registration_page = self::has_key( 'registration_page', $metadata ) ? self::get_key_value( 'registration_page', $metadata ) : \get_user_meta( $user->ID, Reader_Activation::REGISTRATION_PAGE, true );
		if ( ! empty( $registration_page ) ) {
			$metadata['registration_page'] = $registration_page;
		}

		$connected_account = self::has_key( 'connected_account', $metadata ) ? self::get_key_value( 'connected_account', $metadata ) : \get_user_meta( $user->ID, Reader_Activation::CONNECTED_ACCOUNT, true );
		if ( ! empty( $connected_account ) && in_array( $connected_account, Reader_Activation::SSO_REGISTRATION_METHODS, true ) ) {
			$metadata['connected_account'] = $connected_account;
		} elseif ( ! empty( $registration_method ) && in_array( $registration_method, Reader_Activation::SSO_REGISTRATION_METHODS, true ) ) {
			$metadata['connected_account'] = $registration_method;
		}

		return $metadata;
	}

	/**
	 * Expand UTM parameters from page URLs into raw suffixed keys.
	 *
	 * Raw-key port of the legacy UTM expansion: emits keys like
	 * `signup_page_utm_source` / `payment_page_utm_campaign` instead of
	 * prefixed ESP names. Existing values are never overwritten.
	 *
	 * @param array $metadata Metadata to add to.
	 *
	 * @return array Metadata with UTM fields added.
	 */
	public static function add_utm_data_raw( $metadata ) {
		$has_page = self::has_key( 'current_page_url', $metadata ) || self::has_key( 'registration_page', $metadata ) || self::has_key( 'payment_page', $metadata );
		if ( ! $has_page ) {
			return $metadata;
		}

		$payment_page = self::has_key( 'payment_page', $metadata ) ? self::get_key_value( 'payment_page', $metadata ) : false;
		if ( ! empty( $payment_page ) ) {
			$raw_url = $payment_page;
		} elseif ( self::has_key( 'current_page_url', $metadata ) ) {
			$raw_url = self::get_key_value( 'current_page_url', $metadata );
		} else {
			$raw_url = self::get_key_value( 'registration_page', $metadata );
		}

		$parsed_url = \wp_parse_url( $raw_url );
		if ( empty( $parsed_url['query'] ) ) {
			return $metadata;
		}

		$utm_key_prefix = ! empty( $payment_page ) ? 'payment_page_utm' : 'signup_page_utm';
		$params         = [];
		\wp_parse_str( $parsed_url['query'], $params );
		foreach ( $params as $param => $value ) {
			$param = \sanitize_text_field( $param );
			if ( 'utm' !== substr( $param, 0, 3 ) ) {
				continue;
			}
			$key = $utm_key_prefix . '_' . str_replace( 'utm_', '', $param );
			if ( empty( $metadata[ $key ] ) ) {
				$metadata[ $key ] = $value;
			}
		}

		return $metadata;
	}

	/**
	 * Normalizes contact metadata before syncing: raw-key enrichment only.
	 *
	 * Filtering and prefixing happen per integration in
	 * Integration::prepare_contact().
	 *
	 * @param array $contact Contact data.
	 * @return array Normalized contact data.
	 */
	public static function normalize_contact_data( $contact ) {
		if ( ! isset( $contact['metadata'] ) ) {
			$contact['metadata'] = [];
		}
		$contact['metadata'] = self::add_registration_data_raw( $contact['metadata'] );
		$contact['metadata'] = self::add_utm_data_raw( $contact['metadata'] );

		Logger::log( 'Normalizing contact data for reader ESP sync:' );
		Logger::log( $contact );

		/**
		 * Filters the normalized contact data before syncing to the ESP.
		 *
		 * @param array $contact Contact data.
		 */
		return apply_filters( 'newspack_esp_sync_normalize_contact', $contact );
	}
}
