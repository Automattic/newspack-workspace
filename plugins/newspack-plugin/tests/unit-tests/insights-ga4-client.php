<?php
/**
 * Tests for the Insights GA4 Data API client.
 *
 * Covers the deterministic, network-free logic: custom-dimension extraction
 * from both the dimensions array and the dimensionFilter expression tree, the
 * requested-vs-registered diff that drives the `custom_dimension_missing`
 * error, and the property-less guard on the registered-dimensions lookup.
 *
 * The full run_report() success/HTTP paths require a live OAuth connection and
 * are covered by the manual smoke test documented on NPPD-1647.
 *
 * @package Newspack\Tests
 */

use Newspack\Insights\GA4\Client;
use Newspack\GA4_Custom_Dimensions;

/**
 * Test the \Newspack\Insights\GA4\Client class.
 */
class Newspack_Test_Insights_GA4_Client extends WP_UnitTestCase {

	/**
	 * Invoke a private static method on Client via reflection.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Positional arguments.
	 * @return mixed
	 */
	private function invoke_private( $method, array $args ) {
		$reflection = new ReflectionMethod( Client::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( null, ...$args );
	}

	/**
	 * Extracts customEvent dimensions from the `dimensions` array; standard
	 * dimensions are ignored.
	 */
	public function test_extract_custom_dimensions_from_dimensions_array() {
		$body = [
			'dimensions' => [
				[ 'name' => 'date' ],
				[ 'name' => 'customEvent:post_id' ],
				[ 'name' => 'deviceCategory' ],
				[ 'name' => 'customEvent:author' ],
			],
		];

		$this->assertSame(
			[ 'post_id', 'author' ],
			$this->invoke_private( 'extract_custom_dimensions', [ $body ] )
		);
	}

	/**
	 * Detects customEvent dimensions referenced only inside a dimensionFilter
	 * tree (no entry in the dimensions array) — the article-scope pattern used
	 * by screenPageViews-only queries in the Insights formulas.
	 */
	public function test_extract_custom_dimensions_from_filter_tree() {
		$body = [
			'metrics'         => [ [ 'name' => 'screenPageViews' ] ],
			'dimensionFilter' => [
				'andGroup' => [
					'expressions' => [
						[
							'filter' => [
								'fieldName'    => 'eventName',
								'stringFilter' => [
									'matchType' => 'EXACT',
									'value'     => 'scroll',
								],
							],
						],
						[
							'filter' => [
								'fieldName'    => 'customEvent:post_id',
								'stringFilter' => [
									'matchType' => 'FULL_REGEXP',
									'value'     => '.+',
								],
							],
						],
					],
				],
			],
		];

		$this->assertSame(
			[ 'post_id' ],
			$this->invoke_private( 'extract_custom_dimensions', [ $body ] )
		);
	}

	/**
	 * Merges customEvent references from both the dimensions array and the
	 * filter tree, de-duplicating overlaps.
	 */
	public function test_extract_custom_dimensions_merges_and_dedupes() {
		$body = [
			'dimensions'      => [
				[ 'name' => 'customEvent:author' ],
			],
			'dimensionFilter' => [
				'andGroup' => [
					'expressions' => [
						[
							'filter' => [
								'fieldName'    => 'customEvent:post_id',
								'stringFilter' => [ 'value' => '.+' ],
							],
						],
						[
							'filter' => [
								'fieldName'    => 'customEvent:author',
								'stringFilter' => [ 'value' => '.+' ],
							],
						],
					],
				],
			],
		];

		$result = $this->invoke_private( 'extract_custom_dimensions', [ $body ] );
		sort( $result );
		$this->assertSame( [ 'author', 'post_id' ], $result );
	}

	/**
	 * A query with no customEvent references yields an empty list (so run_report
	 * skips the registration pre-check entirely).
	 */
	public function test_extract_custom_dimensions_returns_empty_when_none() {
		$body = [
			'dimensions' => [
				[ 'name' => 'date' ],
				[ 'name' => 'deviceCategory' ],
			],
			'metrics'    => [ [ 'name' => 'totalUsers' ] ],
		];

		$this->assertSame( [], $this->invoke_private( 'extract_custom_dimensions', [ $body ] ) );
	}

	/**
	 * The requested-vs-registered diff that drives the custom_dimension_missing
	 * error: a body referencing post_id and author, against a property that has
	 * only post_id registered, yields author as missing. Mirrors the inline
	 * computation in run_report().
	 */
	public function test_missing_dimension_diff_against_registered_set() {
		$body = [
			'dimensions'      => [ [ 'name' => 'customEvent:author' ] ],
			'dimensionFilter' => [
				'filter' => [
					'fieldName'    => 'customEvent:post_id',
					'stringFilter' => [ 'value' => '.+' ],
				],
			],
		];

		$requested  = $this->invoke_private( 'extract_custom_dimensions', [ $body ] );
		$registered = [ 'post_id' ];
		$missing    = array_values( array_diff( $requested, $registered ) );

		$this->assertSame( [ 'author' ], $missing );
	}

	/**
	 * A bare `customEvent:` reference with no parameter name is dropped rather
	 * than surfacing as an empty entry in the missing-dimension diff/message.
	 */
	public function test_extract_custom_dimensions_drops_empty_param() {
		$body = [
			'dimensions' => [
				[ 'name' => 'customEvent:' ],
				[ 'name' => 'customEvent:post_id' ],
			],
		];

		$this->assertSame(
			[ 'post_id' ],
			$this->invoke_private( 'extract_custom_dimensions', [ $body ] )
		);
	}

	/**
	 * Returns a clear WP_Error from run_report() for an empty/whitespace
	 * property ID before doing any other work.
	 */
	public function test_run_report_rejects_empty_property_id() {
		$result = Client::run_report( '   ', [ 'metrics' => [ [ 'name' => 'totalUsers' ] ] ] );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_property_id', $result->get_error_code() );
	}

	/**
	 * Returns a WP_Error from get_registered_parameter_names() when no GA4
	 * property is configured in Site Kit — the guard that lets run_report() fall
	 * through to the live API rather than wrongly reporting every dimension as missing.
	 */
	public function test_registered_parameter_names_without_property_returns_wp_error() {
		$previous = get_option( 'googlesitekit_analytics-4_settings', false );
		delete_option( 'googlesitekit_analytics-4_settings' );

		try {
			$result = GA4_Custom_Dimensions::get_registered_parameter_names();
			$this->assertWPError( $result );
		} finally {
			if ( false !== $previous ) {
				update_option( 'googlesitekit_analytics-4_settings', $previous );
			}
		}
	}
}
