<?php
/**
 * Promoted Fields — registers integration-pulled fields as access rules and segmentation criteria.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation;

use Newspack\Access_Rules;
use Newspack\Reader_Data;
use Newspack\Reader_Activation\Integration;
use Newspack\Reader_Activation\Integrations\Contact_Pull;
use Newspack\Reader_Activation\Integrations\Incoming_Field;

defined( 'ABSPATH' ) || exit;

/**
 * Promoted Fields class.
 */
class Promoted_Fields {

	/**
	 * Cache for promoted fields.
	 *
	 * @var array|null
	 */
	private static $promoted_fields = null;

	/**
	 * Matching functions that predate the newspack-popups capability probe. Any
	 * newspack-popups build old enough to lack `supports_matching_function()`
	 * still resolves every one of these, so they never need gating — only
	 * operators introduced alongside or after the probe do.
	 *
	 * @var string[]
	 */
	private const BASELINE_MATCHING_FUNCTIONS = [ 'default', 'range', 'list__in', 'list__not_in' ];

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ], 15 );
	}

	/**
	 * Register promoted fields as access rules and segmentation criteria.
	 */
	public static function register() {
		$fields = self::get_promoted_fields();
		self::register_access_rules( $fields );
		self::register_segment_criteria( $fields );
	}

	/**
	 * Get all promoted fields from active integrations.
	 *
	 * @return array Promoted fields as [ namespaced_key => [ 'field' => Incoming_Field, 'integration' => Integration ] ].
	 */
	public static function get_promoted_fields() {
		if ( null !== self::$promoted_fields ) {
			return self::$promoted_fields;
		}

		$fields = [];

		if ( ! class_exists( '\Newspack\Reader_Activation\Integrations' ) ) {
			self::$promoted_fields = $fields;
			return $fields;
		}

		$integrations = Integrations::get_active_integrations();
		if ( ! is_array( $integrations ) ) {
			self::$promoted_fields = $fields;
			return $fields;
		}

		foreach ( $integrations as $integration ) {
			foreach ( $integration->get_enabled_incoming_fields() as $field ) {
				if ( ! $field->is_access_rule() && ! $field->is_segment_criteria() ) {
					continue;
				}
				// Namespace the key with the integration ID to avoid collisions.
				$namespaced_key            = $integration->get_id() . '__' . $field->get_key();
				$fields[ $namespaced_key ] = [
					'field'       => $field,
					'integration' => $integration,
				];
			}
		}

		/**
		 * Filters the promoted fields available as access rules and segmentation criteria.
		 *
		 * @param array $fields Promoted fields keyed by namespaced key.
		 */
		self::$promoted_fields = apply_filters( 'newspack_integration_promoted_fields', $fields );
		return self::$promoted_fields;
	}

	/**
	 * Reset the cached promoted fields. Useful for testing.
	 */
	public static function reset_cache() {
		self::$promoted_fields = null;
	}

	/**
	 * Get the display name for a promoted field, prefixed with the integration name.
	 *
	 * @param Incoming_Field $field       The field.
	 * @param Integration    $integration The integration.
	 *
	 * @return string
	 */
	private static function get_display_name( $field, $integration ) {
		return sprintf( '%s: %s', $integration->get_name(), $field->get_name() );
	}

	/**
	 * Register promoted fields as content gate access rules.
	 *
	 * @param array $fields Promoted fields.
	 */
	private static function register_access_rules( $fields ) {
		foreach ( $fields as $key => $entry ) {
			$field       = $entry['field'];
			$integration = $entry['integration'];

			if ( ! $field->is_access_rule() ) {
				continue;
			}
			Access_Rules::register_rule(
				[
					'id'          => $key,
					'name'        => self::get_display_name( $field, $integration ),
					'description' => $field->get_description(),
					'options'     => $field->get_options(),
					'is_boolean'  => 'boolean' === $field->get_value_type(),
					'callback'    => function ( $user_id, $args ) use ( $field ) {
						return self::evaluate_field( $field, $user_id, $args );
					},
				]
			);
		}
	}

	/**
	 * Register promoted fields as popups segmentation criteria.
	 *
	 * @param array $fields Promoted fields.
	 */
	private static function register_segment_criteria( $fields ) {
		if ( ! class_exists( '\Newspack_Popups_Criteria' ) ) {
			return;
		}
		foreach ( $fields as $key => $entry ) {
			$field       = $entry['field'];
			$integration = $entry['integration'];

			if ( ! $field->is_segment_criteria() ) {
				continue;
			}
			$options = $field->get_options();

			// Boolean fields get Yes/No options for segmentation.
			if ( 'boolean' === $field->get_value_type() && empty( $options ) ) {
				$options = [
					[
						'value' => 'yes',
						'label' => __( 'Yes', 'newspack-plugin' ),
					],
					[
						'value' => 'no',
						'label' => __( 'No', 'newspack-plugin' ),
					],
				];
			}

			// Prepend an empty "Any" option so the criterion can be left unset.
			if ( ! empty( $options ) ) {
				array_unshift(
					$options,
					[
						'value' => '',
						'label' => __( 'Any', 'newspack-plugin' ),
					]
				);
			}

			\Newspack_Popups_Criteria::register_criteria(
				$key,
				[
					'name'               => self::get_display_name( $field, $integration ),
					'category'           => 'integrations',
					'matching_function'  => self::supported_matching_function( $field->get_matching_function() ),
					'matching_attribute' => $field->get_key(),
					'options'            => $options,
					'description'        => $field->get_description(),
				]
			);
		}
	}

	/**
	 * Resolve a matching function the installed newspack-popups can actually run.
	 *
	 * Newer operators ship here before newspack-popups is updated to match, and an
	 * old build leaves an unresolvable name as a raw string and then calls it —
	 * a TypeError that escapes segment matching and takes prompt display down
	 * sitewide, not just for that segment. Newer builds fail closed on their own,
	 * but that guard is exactly what a stale build lacks, so ask first and fall
	 * back to exact matching (a criterion that matches too narrowly, rather than
	 * one that breaks the page).
	 *
	 * @param string $matching_function The field's matching function.
	 * @return string A matching function the installed newspack-popups supports.
	 */
	private static function supported_matching_function( $matching_function ) {
		if ( in_array( $matching_function, self::BASELINE_MATCHING_FUNCTIONS, true ) ) {
			return $matching_function;
		}
		// An older newspack-popups has no such method. Its absence means "cannot
		// confirm support", which for anything past the baseline means don't emit it.
		if ( ! method_exists( '\Newspack_Popups_Criteria', 'supports_matching_function' ) ) {
			return 'default';
		}
		return \Newspack_Popups_Criteria::supports_matching_function( $matching_function ) ? $matching_function : 'default';
	}

	/**
	 * Evaluate a promoted field for a given user.
	 *
	 * @param Incoming_Field $field   The field.
	 * @param int            $user_id User ID.
	 * @param mixed          $args    Rule arguments (value to match against).
	 *
	 * @return bool Whether the field matches.
	 */
	private static function evaluate_field( $field, $user_id, $args ) {
		// Custom callback takes precedence.
		$callback = $field->get_access_rule_callback();
		if ( is_callable( $callback ) ) {
			return (bool) call_user_func( $callback, $user_id, $args );
		}

		$raw_value = Reader_Data::get_data( $user_id, $field->get_key() );
		$value     = self::decode_value( $raw_value );

		// Boolean fields: access rules pass no args (just check truthiness),
		// segmentation passes 'yes'/'no'.
		if ( 'boolean' === $field->get_value_type() ) {
			$is_truthy = ! empty( $value );

			// Segmentation: expects 'yes'/'no' (case-insensitive) as string arguments.
			if ( is_string( $args ) ) {
				$normalized = strtolower( $args );
				if ( 'yes' === $normalized ) {
					return $is_truthy;
				}
				if ( 'no' === $normalized ) {
					return ! $is_truthy;
				}
			}

			// Access rule with is_boolean or non-string args: just check truthiness.
			return $is_truthy;
		}

		switch ( $field->get_matching_function() ) {
			case 'range':
				$min = $args['min'] ?? 0;
				$max = $args['max'] ?? PHP_INT_MAX;
				return (float) $value >= (float) $min && (float) $value <= (float) $max;
			case 'list__in':
				$user_values = self::parse_list_value( $value );
				return ! empty( array_intersect( (array) $args, $user_values ) );
			case 'list__not_in':
				$user_values = self::parse_list_value( $value );
				return empty( array_intersect( (array) $args, $user_values ) );
			case 'date_range':
				// Access rules have no range UI — a rule still holds one typed value and
				// still matches it exactly. But selecting this operator makes the pull
				// rewrite stored values to ISO, so a rule the publisher wrote in the
				// provider's own format ('03/04/2026') would silently stop matching a
				// stored '2026-03-04'. Put both sides through the same normalizer and
				// compare calendar dates, so the rule keeps meaning what it meant.
				$stored_date = self::to_calendar_date( $value );
				$rule_date   = self::to_calendar_date(
					Contact_Pull::normalize_date_value( $args, $field->get_date_format(), $field->get_value_type() )
				);
				return null !== $stored_date && $stored_date === $rule_date;
			default:
				return $value === $args;
		}
	}

	/**
	 * Reduce a date value to its calendar date, or null when it isn't one.
	 *
	 * Mirrors the client matcher's `toCalendarDate()` (newspack-popups
	 * `src/criteria/matching-functions.js`) so both consumers of a `date_range`
	 * field agree on what counts as a date, and on reading the date part as
	 * written rather than shifting it into a timezone.
	 *
	 * @param mixed $value Candidate value.
	 * @return string|null A `YYYY-MM-DD` string, or null.
	 */
	private static function to_calendar_date( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$candidate = substr( trim( $value ), 0, 10 );
		if ( 1 !== preg_match( '/^(\d{4})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $candidate, $matches ) ) {
			return null;
		}
		// The bounded pattern still admits a day that doesn't exist in its month
		// (`2026-02-30`), which the normalizer stores untouched precisely because it
		// isn't a date. It must not become one here either.
		return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] ) ? $candidate : null;
	}

	/**
	 * Decode a stored value from Reader_Data.
	 *
	 * @param mixed $value The raw stored value.
	 *
	 * @return mixed The decoded value.
	 */
	private static function decode_value( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		$decoded = json_decode( $value, true );
		if ( null !== $decoded || 'null' === $value ) {
			return $decoded;
		}
		// Not valid JSON — return as-is.
		return $value;
	}

	/**
	 * Parse a value into an array for list matching.
	 *
	 * Recognizes ActiveCampaign's `||val1||val2||` multi-select delimiter so that
	 * matching against AC checkbox / multiselect fields works under 'list__in' /
	 * 'list__not_in' matching functions. Other multi-value formats fall through
	 * as a single-element list.
	 *
	 * @param mixed $value The decoded value.
	 *
	 * @return array
	 */
	private static function parse_list_value( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && '' !== $value ) {
			// AC always wraps multi-select values with leading and trailing `||`
			// (`||A||` for one selection, `||A||B||` for many). Require both ends to
			// match so a non-AC string that happens to contain `||` mid-value is left
			// alone.
			if ( str_starts_with( $value, '||' ) && str_ends_with( $value, '||' ) ) {
				$parts = array_map( 'trim', explode( '||', $value ) );
				return array_values( array_filter( $parts, static fn( $part ) => '' !== $part ) );
			}
			return [ $value ];
		}
		return [];
	}
}
Promoted_Fields::init();
