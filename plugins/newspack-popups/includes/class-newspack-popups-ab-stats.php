<?php
/**
 * Statistics for Campaigns A/B tests.
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Conversion rate, lift and confidence for a two-arm A/B test.
 *
 * Confidence is a one-sided figure: how sure we are that the arm with the
 * higher rate is really ahead, from a two-proportion z-test with pooled
 * variance and the Abramowitz and Stegun polynomial for the normal CDF
 * (formula 26.2.17), as the archived Automattic/newspack-ab-test proof of
 * concept computed it. The 80 and 95 thresholds were chosen against that
 * figure, so a two-sided one would move them.
 */
final class Newspack_Popups_AB_Stats {
	/**
	 * Readers an arm needs before a confidence figure is shown at all.
	 */
	const MIN_READERS_PER_ARM = 30;

	/**
	 * Confidence, in percent, at which a result reads as directional.
	 */
	const THRESHOLD_DIRECTIONAL = 80;

	/**
	 * Confidence, in percent, at which a result reads as reliable.
	 */
	const THRESHOLD_RELIABLE = 95;

	/**
	 * Conversions per hundred readers.
	 *
	 * @param int $readers     Readers who saw the variant.
	 * @param int $conversions Readers who converted.
	 * @return float|null Two decimals, or null without readers.
	 */
	public static function rate( $readers, $conversions ) {
		if ( $readers <= 0 ) {
			return null;
		}
		return round( $conversions / $readers * 100, 2 );
	}

	/**
	 * A challenger's rate relative to the control's.
	 *
	 * @param float|null $control_rate    Control conversion rate.
	 * @param float|null $challenger_rate Challenger conversion rate.
	 * @return float|null Percent, one decimal, or null when undefined.
	 */
	public static function lift( $control_rate, $challenger_rate ) {
		if ( null === $control_rate || null === $challenger_rate || 0.0 === (float) $control_rate ) {
			return null;
		}
		return round( ( $challenger_rate - $control_rate ) / $control_rate * 100, 1 );
	}

	/**
	 * One-sided confidence that the arm with the higher observed rate is
	 * really ahead of the other.
	 *
	 * @param int $control_readers        Readers who saw the control.
	 * @param int $control_conversions    Control conversions.
	 * @param int $challenger_readers     Readers who saw the challenger.
	 * @param int $challenger_conversions Challenger conversions.
	 * @return float|null Percent, one decimal; null below the per-arm floor,
	 *                    at a pooled rate of 0 or 1, or at zero standard error.
	 */
	public static function confidence( $control_readers, $control_conversions, $challenger_readers, $challenger_conversions ) {
		if ( $control_readers < self::MIN_READERS_PER_ARM || $challenger_readers < self::MIN_READERS_PER_ARM ) {
			return null;
		}
		$pooled = ( $control_conversions + $challenger_conversions ) / ( $control_readers + $challenger_readers );
		if ( $pooled <= 0 || $pooled >= 1 ) {
			return null;
		}
		$standard_error = sqrt( $pooled * ( 1 - $pooled ) * ( 1 / $control_readers + 1 / $challenger_readers ) );
		if ( 0.0 === $standard_error ) {
			return null;
		}
		$z = abs( $control_conversions / $control_readers - $challenger_conversions / $challenger_readers ) / $standard_error;

		$t   = 1 / ( 1 + 0.2316419 * $z );
		$d   = 0.3989423 * exp( -( $z * $z ) / 2 );
		$cdf = 1 - $d * $t * ( 0.3193815 + $t * ( -0.3565638 + $t * ( 1.7814779 + $t * ( -1.8212560 + $t * 1.3302744 ) ) ) );

		return round( $cdf * 100, 1 );
	}

	/**
	 * The label a confidence figure earns.
	 *
	 * @param float|null $confidence Percent, or null when not computable.
	 * @return string 'collecting', 'directional' or 'reliable'.
	 */
	public static function label( $confidence ) {
		if ( null === $confidence || $confidence < self::THRESHOLD_DIRECTIONAL ) {
			return 'collecting';
		}
		return $confidence < self::THRESHOLD_RELIABLE ? 'directional' : 'reliable';
	}
}
