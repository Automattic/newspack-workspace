<?php
/**
 * Class Newsletters Test Mailchimp Cached Data
 *
 * @package Newspack_Newsletters
 */

/**
 * Tests the Mailchimp Cached Data Class.
 */
class Newsletters_Mailchimp_Cached_Data_Test extends WP_UnitTestCase {
	/**
	 * Audience used by the cache refresh tests.
	 *
	 * @var string
	 */
	const LIST_ID = 'list1';

	/**
	 * GET requests the mock received, as [ endpoint, args ].
	 *
	 * @var array
	 */
	private static $requests = [];

	/**
	 * Whether the mocked segment counts request fails.
	 *
	 * @var bool
	 */
	private static $fail_counts = false;

	/**
	 * Setup.
	 */
	public function set_up() {
		parent::set_up();
		// Reset the API key.
		delete_option( 'newspack_mailchimp_api_key' );
		delete_option( 'newspack_nl_mailchimp_cache_segment_counts_' . self::LIST_ID );
		self::$requests    = [];
		self::$fail_counts = false;
	}

	/**
	 * Teardown.
	 */
	public function tear_down() {
		remove_filter( 'mailchimp_mock_get', [ __CLASS__, 'mock_get' ], 10 );
		parent::tear_down();
	}

	/**
	 * Test the API setup.
	 */
	public function test_mailchimp_cached_data_api_setup() {
		// Makes sure cached data fetch_methods throw an exception in case of error.
		$this->expectException( Exception::class );
		$segments = Newspack_Newsletters_Mailchimp_Cached_Data::fetch_segments( 'list1' );
	}

	/**
	 * Mock Mailchimp GET responses for a cache refresh.
	 *
	 * @param array  $response The default response.
	 * @param string $endpoint The endpoint.
	 * @param array  $args     The query args.
	 * @return array
	 */
	public static function mock_get( $response, $endpoint, $args = [] ) {
		self::$requests[] = [ $endpoint, $args ];
		$list             = self::LIST_ID;

		switch ( $endpoint ) {
			case "lists/$list/segments":
				if ( 'static' === ( $args['type'] ?? '' ) ) {
					return [ 'segments' => [] ];
				}
				if ( self::is_counts_request( $args ) ) {
					// An empty response makes validate() throw, like a timeout.
					return self::$fail_counts ? [] : [
						'segments' => [
							[
								'id'           => 1,
								'member_count' => 10,
							],
							[
								'id'           => 2,
								'member_count' => 20,
							],
						],
					];
				}
				return [
					'segments' => [
						[
							'id'      => 1,
							'name'    => 'Segment one',
							'list_id' => $list,
						],
						[
							'id'      => 2,
							'name'    => 'Segment two',
							'list_id' => $list,
						],
					],
				];
			case "lists/$list/interest-categories":
				return [ 'categories' => [] ];
			case 'campaign-folders':
				return [ 'folders' => [] ];
			case "lists/$list/merge-fields":
				return [ 'merge_fields' => [] ];
		}
		return $response;
	}

	/**
	 * Whether the args are those of the segment counts request.
	 *
	 * @param array $args The query args.
	 * @return bool
	 */
	private static function is_counts_request( $args ) {
		return 'segments.id,segments.member_count' === ( $args['fields'] ?? '' );
	}

	/**
	 * Number of segment counts requests the mock received.
	 *
	 * @return int
	 */
	private static function count_counts_requests() {
		return count(
			array_filter(
				self::$requests,
				function ( $request ) {
					return self::is_counts_request( $request[1] );
				}
			)
		);
	}

	/**
	 * Runs the private cache refresh for the test audience.
	 *
	 * @return array The refreshed list data.
	 */
	private function refresh() {
		update_option( 'newspack_mailchimp_api_key', 'test-key-us1' );
		add_filter( 'mailchimp_mock_get', [ __CLASS__, 'mock_get' ], 10, 3 );
		$method = new ReflectionMethod( 'Newspack_Newsletters_Mailchimp_Cached_Data', 'refresh_cached_data' );
		$method->setAccessible( true );
		return $method->invoke( null, self::LIST_ID );
	}

	/**
	 * Stores segment counts for the test audience.
	 *
	 * @param array $counts    Counts keyed by segment ID.
	 * @param int   $date      When the counts were fetched.
	 * @param int   $attempted When counts were last requested.
	 */
	private function store_counts( $counts, $date, $attempted ) {
		update_option(
			'newspack_nl_mailchimp_cache_segment_counts_' . self::LIST_ID,
			[
				'counts'    => $counts,
				'date'      => $date,
				'attempted' => $attempted,
			]
		);
	}

	/**
	 * Saved segments are fetched without counts on every refresh.
	 */
	public function test_segments_request_excludes_member_count() {
		$this->refresh();
		$segment_requests = array_filter(
			self::$requests,
			function ( $request ) {
				return 'saved' === ( $request[1]['type'] ?? '' ) && ! self::is_counts_request( $request[1] );
			}
		);
		$this->assertCount( 1, $segment_requests );
		$this->assertSame( 'segments.member_count', array_values( $segment_requests )[0][1]['exclude_fields'] ?? null );
	}

	/**
	 * With no stored counts, the refresh fetches them, stores them and merges them into the segments.
	 */
	public function test_counts_fetched_when_missing() {
		$data = $this->refresh();

		$this->assertSame( 1, self::count_counts_requests() );
		$this->assertSame( 10, $data['segments'][0]['member_count'] );
		$this->assertSame( 20, $data['segments'][1]['member_count'] );
		$stored = get_option( 'newspack_nl_mailchimp_cache_segment_counts_' . self::LIST_ID );
		$this->assertSame(
			[
				1 => 10,
				2 => 20,
			],
			$stored['counts']
		);
	}

	/**
	 * Fresh counts are reused; a segment without a stored count gets no member_count.
	 */
	public function test_fresh_counts_are_reused() {
		$this->store_counts( [ 1 => 5 ], time(), time() );

		$data = $this->refresh();

		$this->assertSame( 0, self::count_counts_requests() );
		$this->assertSame( 5, $data['segments'][0]['member_count'] );
		$this->assertArrayNotHasKey( 'member_count', $data['segments'][1] );
	}

	/**
	 * Counts older than the refresh interval are fetched again.
	 */
	public function test_old_counts_are_refetched() {
		$old = time() - Newspack_Newsletters_Mailchimp_Cached_Data::SEGMENT_COUNTS_REFRESH_INTERVAL - 1;
		$this->store_counts( [ 1 => 5 ], $old, $old );

		$data = $this->refresh();

		$this->assertSame( 1, self::count_counts_requests() );
		$this->assertSame( 10, $data['segments'][0]['member_count'] );
	}

	/**
	 * A failed counts request keeps the old counts, still refreshes the cache, and is not retried right away.
	 */
	public function test_failed_counts_keep_old_counts_and_wait_to_retry() {
		$old = time() - Newspack_Newsletters_Mailchimp_Cached_Data::SEGMENT_COUNTS_REFRESH_INTERVAL - 1;
		$this->store_counts( [ 1 => 5 ], $old, $old );
		self::$fail_counts = true;

		$data = $this->refresh();

		$this->assertSame( 1, self::count_counts_requests() );
		$this->assertSame( 5, $data['segments'][0]['member_count'] );
		$this->assertSame( 'Segment one', get_option( 'newspack_nl_mailchimp_cache_' . self::LIST_ID )['segments'][0]['name'] );
		$stored = get_option( 'newspack_nl_mailchimp_cache_segment_counts_' . self::LIST_ID );
		$this->assertSame( $old, $stored['date'] );

		// The next refresh is within the retry interval, so the counts are not requested again.
		self::$requests = [];
		$this->refresh();
		$this->assertSame( 0, self::count_counts_requests() );
	}
}
