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
	 * Setup.
	 */
	public function set_up() {
		// Reset the API key.
		delete_option( 'newspack_mailchimp_api_key' );
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
	 * A cache miss dispatches a background refresh that writes the option moments
	 * later, from another request. WordPress remembers a missing option in its
	 * `notoptions` cache and then answers later reads from that memory without
	 * querying the database, so the write stays invisible and the data never
	 * appears. Reading the cache must not leave that memory behind.
	 */
	public function test_cold_read_does_not_remember_the_option_as_missing() {
		$list_id = 'audColdRead';
		$key     = 'newspack_nl_mailchimp_cache_' . $list_id;

		delete_option( $key );
		wp_cache_delete( 'notoptions', 'options' );

		add_filter( 'pre_http_request', [ $this, 'block_http' ], 10, 3 );
		Newspack_Newsletters_Mailchimp_Cached_Data::get_tags( $list_id );
		remove_filter( 'pre_http_request', [ $this, 'block_http' ], 10 );

		$notoptions = wp_cache_get( 'notoptions', 'options' );

		$this->assertFalse(
			is_array( $notoptions ) && isset( $notoptions[ $key ] ),
			'A cold read must not leave the option remembered as missing.'
		);
	}

	/**
	 * Once the option has been forgotten, a value written by another process is
	 * visible to the next read instead of being masked by the cached absence.
	 */
	public function test_value_written_after_a_cold_read_is_visible() {
		$list_id = 'audWrittenAfter';
		$key     = 'newspack_nl_mailchimp_cache_' . $list_id;

		delete_option( $key );
		wp_cache_delete( 'notoptions', 'options' );

		add_filter( 'pre_http_request', [ $this, 'block_http' ], 10, 3 );
		Newspack_Newsletters_Mailchimp_Cached_Data::get_tags( $list_id );
		remove_filter( 'pre_http_request', [ $this, 'block_http' ], 10 );

		// Stand in for the background refresh writing straight to the database,
		// as it does from its own request.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$wpdb->options,
			[
				'option_name'  => $key,
				'option_value' => maybe_serialize(
					[
						'tags' => [
							[
								'id'   => 1,
								'name' => 'Warm',
							],
						],
					] 
				),
				'autoload'     => 'no',
			]
		);

		$this->assertIsArray( get_option( $key ), 'The written value must be visible to the next read.' );
	}

	/**
	 * Swallow the async refresh request the cache dispatches on a miss.
	 *
	 * @param mixed  $preempt Whether to preempt the request.
	 * @param array  $args    The request arguments.
	 * @param string $url     The request URL.
	 * @return array
	 */
	public function block_http( $preempt, $args, $url ) {
		return [
			'headers'  => [],
			'body'     => '',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];
	}
}
