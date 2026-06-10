<?php
/**
 * Newspack Insights — GAM report query value object (NPPD-1662).
 *
 * A metric-agnostic, struct-like description of a Google Ad Manager
 * ReportService query. The Advertising metric orchestrator (NPPD-1663)
 * constructs these per metric; {@see Client} translates them into the
 * SOAP ReportQuery objects.
 *
 * @package Newspack
 */

namespace Newspack\Insights\GAM;

defined( 'ABSPATH' ) || exit;

/**
 * Value object describing a GAM report query.
 */
class Report_Query {

	/**
	 * GAM Dimension enum names (the report's GROUP BY).
	 *
	 * @var string[]
	 */
	public $dimensions = [];

	/**
	 * GAM Column enum names (the report's measures).
	 *
	 * @var string[]
	 */
	public $columns = [];

	/**
	 * Optional PQL filter clause (the report's WHERE), or null.
	 *
	 * @var string|null
	 */
	public $pql_filter = null;

	/**
	 * Window start date as 'YYYY-MM-DD'. Used when date_range_type is
	 * CUSTOM_DATE.
	 *
	 * @var string
	 */
	public $start_date = '';

	/**
	 * Window end date as 'YYYY-MM-DD'. Used when date_range_type is
	 * CUSTOM_DATE.
	 *
	 * @var string
	 */
	public $end_date = '';

	/**
	 * GAM DateRangeType enum value. Defaults to CUSTOM_DATE.
	 *
	 * @var string
	 */
	public $date_range_type = 'CUSTOM_DATE';

	/**
	 * Constructor.
	 *
	 * @param array $args Optional associative array of property values
	 *                    keyed by property name.
	 */
	public function __construct( array $args = [] ) {
		$keys = [ 'dimensions', 'columns', 'pql_filter', 'start_date', 'end_date', 'date_range_type' ];
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $args ) ) {
				$this->$key = $args[ $key ];
			}
		}
	}

	/**
	 * A stable hash of the query, for audit logging and cache keys.
	 *
	 * @return string
	 */
	public function hash() {
		return md5(
			(string) wp_json_encode(
				[
					$this->dimensions,
					$this->columns,
					$this->pql_filter,
					$this->start_date,
					$this->end_date,
					$this->date_range_type,
				]
			)
		);
	}
}
