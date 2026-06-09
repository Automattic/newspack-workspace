<?php
/**
 * Newspack Insights — GAM report job status constants (NPPD-1662).
 *
 * Mirrors the Google Ad Manager SOAP API ReportJobStatus enum
 * (v202602). Note: the SOAP enum exposes only IN_PROGRESS, COMPLETED,
 * and FAILED — there is no PENDING value (the original ticket spec
 * listed PENDING, but it does not exist in the API). UNKNOWN is a local
 * fallback for any unrecognized value the API might return.
 *
 * @package Newspack
 */

namespace Newspack\Insights\GAM;

defined( 'ABSPATH' ) || exit;

/**
 * Report job status values.
 */
class Report_Job_Status {

	/**
	 * The report job is still running.
	 *
	 * @var string
	 */
	const IN_PROGRESS = 'IN_PROGRESS';

	/**
	 * The report job finished successfully and is ready to download.
	 *
	 * @var string
	 */
	const COMPLETED = 'COMPLETED';

	/**
	 * The report job failed.
	 *
	 * @var string
	 */
	const FAILED = 'FAILED';

	/**
	 * Local fallback for any value not recognized as one of the API enum
	 * values above.
	 *
	 * @var string
	 */
	const UNKNOWN = 'UNKNOWN';

	/**
	 * Coerce a raw status string from the API into one of the known
	 * constants, falling back to UNKNOWN.
	 *
	 * @param string $status Raw status string.
	 * @return string One of the class constants.
	 */
	public static function normalize( $status ) {
		$known = [ self::IN_PROGRESS, self::COMPLETED, self::FAILED ];
		return in_array( $status, $known, true ) ? $status : self::UNKNOWN;
	}

	/**
	 * Whether a status is terminal (the job will not change state again).
	 *
	 * @param string $status Status value.
	 * @return bool
	 */
	public static function is_terminal( $status ) {
		return in_array( $status, [ self::COMPLETED, self::FAILED ], true );
	}
}
