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
	 * @var array|WP_Error|Throwable
	 */
	private $counts;

	/**
	 * What is_available() returns.
	 *
	 * @var bool|WP_Error|Throwable
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
	 * @param array|WP_Error|Throwable $counts    Result of get_counts(); a Throwable is thrown.
	 * @param bool|WP_Error|Throwable  $available Result of is_available(); a Throwable is thrown.
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
	 *
	 * @throws Throwable When built with one.
	 */
	public function is_available() {
		if ( $this->available instanceof Throwable ) {
			throw $this->available;
		}
		return $this->available;
	}

	/**
	 * Counts.
	 *
	 * @param array  $test  Test.
	 * @param string $goal  Goal.
	 * @param string $start Window start.
	 * @param string $end   Window end.
	 * @throws Throwable When built with one.
	 */
	public function get_counts( array $test, string $goal, string $start, string $end ) {
		$this->calls[] = compact( 'test', 'goal', 'start', 'end' );
		if ( $this->counts instanceof Throwable ) {
			throw $this->counts;
		}
		return $this->counts;
	}
}
