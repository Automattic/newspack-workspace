<?php
/**
 * A stats provider the dashboard tests control.
 *
 * @package Newspack_Popups
 */

/**
 * A provider the tests control: returns what it was built with and records
 * what it was asked.
 */
class AB_Stub_Provider implements Newspack_Popups_AB_Stats_Provider {
	/**
	 * What get_counts() returns.
	 *
	 * @var array|WP_Error
	 */
	private $counts;

	/**
	 * What is_available() returns.
	 *
	 * @var true|WP_Error
	 */
	private $available;

	/**
	 * Arguments of every get_counts() call, oldest first.
	 *
	 * @var array[]
	 */
	public $calls = [];

	/**
	 * Constructor.
	 *
	 * @param array|WP_Error $counts    Result of get_counts().
	 * @param true|WP_Error  $available Result of is_available().
	 */
	public function __construct( $counts, $available = true ) {
		$this->counts    = $counts;
		$this->available = $available;
	}

	/**
	 * Slug.
	 */
	public function get_slug(): string {
		return 'stub';
	}

	/**
	 * Availability.
	 */
	public function is_available() {
		return $this->available;
	}

	/**
	 * Counts.
	 *
	 * @param array  $test  Test.
	 * @param string $goal  Goal.
	 * @param string $start Window start.
	 * @param string $end   Window end.
	 */
	public function get_counts( array $test, string $goal, string $start, string $end ) {
		$this->calls[] = compact( 'test', 'goal', 'start', 'end' );
		return $this->counts;
	}
}
