<?php
/**
 * Date value utilities shared by the ESP pull and the access-rule evaluator.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Static helpers for reading and normalizing incoming-field date values.
 *
 * Lives beside Incoming_Field, which declares the date_format these helpers
 * consume, so the pull job and the access-rule evaluator both depend on a
 * neutral home instead of on each other.
 */
final class Date_Value {

	/**
	 * A calendar date: bounded month and day, so a digit-shaped impossible date
	 * ('2026-13-45') is rejected rather than sorting above every real date.
	 *
	 * The single PHP copy of a pattern that also lives in newspack-popups —
	 * ISO_DATE in src/criteria/matching-functions.js and the criterion schema in
	 * includes/class-newspack-segments-model.php. Those mirrors are load-bearing:
	 * a divergence surfaces as segments that validate and then match nobody.
	 *
	 * @var string
	 */
	private const CALENDAR_DATE_PATTERN = '\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])';

	/**
	 * A time-of-day component as the normalizer emits it (ATOM) or a provider
	 * sends it: 'T' or a space, HH:MM, optional seconds and fraction, optional
	 * zone. Mirrors ISO_TIME_SUFFIX in newspack-popups matching-functions.js.
	 *
	 * @var string
	 */
	private const TIME_SUFFIX_PATTERN = '[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?( ?(Z|[+-]\d{2}:?\d{2}))?';

	/**
	 * Normalize an ESP date value to ISO so the criteria matcher sees one format.
	 *
	 * Resolved at write time, because that is the only moment the provider's own
	 * format metadata is still in hand — `03/04/2026` is genuinely ambiguous
	 * between Mailchimp's two `date_format` settings once it reaches storage.
	 *
	 * A value that cannot be parsed is returned untouched: never destroy
	 * publisher data to satisfy a format. The matcher fails closed on non-ISO
	 * values, and the reader's next pull repairs the entry.
	 *
	 * PHP's general parser is only ever handed an already ISO-shaped value: it
	 * reads an ambiguous slash-separated date American-first (a Mailchimp
	 * DD/MM/YYYY value would land months off), and it silently rolls an
	 * impossible calendar date over (`2026-02-30` becomes `2026-03-02`) instead
	 * of failing. The ISO gate plus the round-trip check below keep every such
	 * case on the "return it unchanged" path.
	 *
	 * @param mixed  $value      Raw value from the integration.
	 * @param string $format     Source format as a PHP date format string. Empty
	 *                           means the provider already sends ISO 8601 / Y-m-d.
	 *                           A format that parses no year is treated as
	 *                           undeclared (see format_specifies_year()).
	 * @param string $value_type Either 'date' or 'datetime'.
	 * @return mixed ISO string, or the value unchanged.
	 */
	public static function normalize( $value, $format = '', $value_type = 'date' ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return $value;
		}

		$trimmed = trim( $value );
		$date    = false;

		if ( '' !== $format && self::format_specifies_year( $format ) ) {
			// The `!` resets fields the format doesn't specify to zero instead of
			// "now", so a datetime under a date-only source format stores the same
			// value on every pull rather than embedding the pull moment.
			$date = \DateTimeImmutable::createFromFormat( '!' . $format, $trimmed );
			// createFromFormat is permissive: it happily parses '2026-13-45' against
			// 'Y-m-d' and rolls the overflow into the next year. Warnings are how it
			// reports that, so treat any as a parse failure. As of PHP 8.2
			// getLastErrors() returns false rather than an array when clean.
			$errors = \DateTimeImmutable::getLastErrors();
			if ( is_array( $errors ) && ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) ) {
				$date = false;
			}
		}

		if ( false === $date ) {
			// Reached when no format was declared, or when a declared one didn't fit.
			// An ISO value arriving under a declared slash format is the legitimate
			// rescue here; anything else is ambiguous and must not be guessed at.
			if ( ! self::is_iso_shaped( $trimmed ) ) {
				return $value;
			}
			try {
				$date = new \DateTimeImmutable( $trimmed );
			} catch ( \Exception $e ) {
				return $value;
			}
			// The parser rolls an out-of-calendar day over rather than throwing, so
			// the date part has to survive the round trip to be trusted.
			if ( $date->format( 'Y-m-d' ) !== substr( $trimmed, 0, 10 ) ) {
				return $value;
			}
		}

		return $date->format( 'datetime' === $value_type ? \DateTimeInterface::ATOM : 'Y-m-d' );
	}

	/**
	 * Reduce a date value to its calendar date, or null when it isn't one.
	 *
	 * Mirrors the client matcher's `toCalendarDate()` (newspack-popups
	 * src/criteria/matching-functions.js) so both consumers of a `date_range`
	 * field agree on what counts as a date, and on reading the date part as
	 * written rather than shifting it into a timezone. The whole string is
	 * validated, not just its first ten characters: a value the normalizer
	 * refused to trust ('2026-01-15 TBD') must not match as its ISO-looking
	 * prefix.
	 *
	 * @param mixed $value Candidate value.
	 * @return string|null A `YYYY-MM-DD` string, or null.
	 */
	public static function to_calendar_date( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$trimmed   = trim( $value );
		$candidate = substr( $trimmed, 0, 10 );
		if ( 1 !== preg_match( '/^' . self::CALENDAR_DATE_PATTERN . '$/', $candidate ) ) {
			return null;
		}
		$suffix = substr( $trimmed, 10 );
		if ( '' !== $suffix && 1 !== preg_match( '/^' . self::TIME_SUFFIX_PATTERN . '$/', $suffix ) ) {
			return null;
		}
		// The bounded pattern still admits a day that doesn't exist in its month
		// ('2026-02-30'), which the normalizer stores untouched precisely because
		// it isn't a date. It must not become one here.
		list( $year, $month, $day ) = array_map( 'intval', explode( '-', $candidate ) );
		return checkdate( $month, $day, $year ) ? $candidate : null;
	}

	/**
	 * Whether a PHP date format string parses a year.
	 *
	 * `createFromFormat()` accepts a year-less format (`m/d`) without complaint —
	 * the `!` prefix resets the unspecified year to the Unix epoch, so `03/04`
	 * becomes a confident, well-formed `1970-03-04` that nothing downstream can
	 * tell from a real date. The in-tree provider mappers never emit such a
	 * format (Mailchimp's year-less `birthday` is deliberately not a date type),
	 * but any third-party `set_date_format()` caller can. Backslash-escaped
	 * literals (`\Y`) don't count as specifiers; `U` (Unix timestamp) pins a
	 * full moment without a year letter, so it does. `r` and `c` also would,
	 * but createFromFormat() cannot parse either, so declaring them lands on
	 * the fail-closed path regardless of this check.
	 *
	 * @param string $format PHP date format string.
	 * @return bool
	 */
	private static function format_specifies_year( $format ) {
		return 1 === preg_match( '/(?<!\\\\)[YyoXxU]/', $format );
	}

	/**
	 * Whether a string is already ISO-shaped: a `YYYY-MM-DD` date, optionally
	 * followed by a time component. Gates the fallback parse in normalize() so
	 * an ambiguous non-ISO value isn't handed to PHP's general parser.
	 *
	 * Looser about the suffix than to_calendar_date() on purpose: anything after
	 * the date may be offered to the parser, because the round-trip check in
	 * normalize() rejects whatever the parser couldn't honestly read.
	 *
	 * @param string $value Trimmed candidate value.
	 * @return bool
	 */
	private static function is_iso_shaped( $value ) {
		return 1 === preg_match( '/^' . self::CALENDAR_DATE_PATTERN . '([T\s].*)?$/', $value );
	}
}
