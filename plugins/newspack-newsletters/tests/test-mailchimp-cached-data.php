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
	 * The composed subscription-lists cache must be invalidated when a Mailchimp
	 * audience's sublists finish warming, so a cold-cache read that returned
	 * audiences only is not served past the point where groups/tags/segments
	 * become available.
	 */
	public function test_cache_updated_action_clears_composed_lists_cache() {
		$this->assertNotFalse(
			has_action(
				'newspack_newsletters_mailchimp_cache_updated',
				[ 'Newspack_Newsletters_Subscription', 'clear_lists_cache_on_warm' ]
			),
			'clear_lists_cache_on_warm should be hooked to the Mailchimp cache-updated action.'
		);

		Newspack_Newsletters::set_service_provider( 'mailchimp' );
		$cache_key = 'newspack_newsletters_lists_mailchimp';
		set_transient(
			$cache_key,
			[
				'lists'    => [ [ 'id' => 'audience-only' ] ],
				'complete' => false,
			],
			HOUR_IN_SECONDS
		);
		$this->assertIsArray( get_transient( $cache_key ), 'Composed lists cache should be primed.' );

		do_action( 'newspack_newsletters_mailchimp_cache_updated', 'audience-only', [ 'tags' => [] ] );

		$this->assertFalse(
			get_transient( $cache_key ),
			'Composed lists cache should be cleared once the audience sublists are warmed.'
		);
	}

	/**
	 * The pending-sublists check should report a cold sublist cache so the
	 * subscription layer can avoid caching or garbage-collecting an
	 * audience-only snapshot.
	 */
	public function test_has_pending_sublists() {
		$lists_key    = 'newspack_nl_mailchimp_cache_lists';
		$sublists_key = 'newspack_nl_mailchimp_cache_aud1';

		// No audiences known yet: nothing is pending at the sublist level.
		delete_option( $lists_key );
		delete_option( $sublists_key );
		$this->assertFalse( Newspack_Newsletters_Mailchimp_Cached_Data::has_pending_sublists() );

		// Audience is known but its sublist cache is still cold: pending.
		update_option(
			$lists_key,
			[
				[
					'id'   => 'aud1',
					'name' => 'Audience 1',
				],
			]
		);
		$this->assertTrue( Newspack_Newsletters_Mailchimp_Cached_Data::has_pending_sublists() );

		// Sublist cache warmed: no longer pending.
		update_option(
			$sublists_key,
			[
				'tags'                => [],
				'segments'            => [],
				'interest_categories' => [],
			]
		);
		$this->assertFalse( Newspack_Newsletters_Mailchimp_Cached_Data::has_pending_sublists() );

		delete_option( $lists_key );
		delete_option( $sublists_key );
	}

	/**
	 * The lists-complete filter must veto completeness while sublists are cold,
	 * which is what gates garbage collection and caching in
	 * Newspack_Newsletters_Subscription::get_lists().
	 */
	public function test_filter_lists_complete_reflects_pending_sublists() {
		$lists_key    = 'newspack_nl_mailchimp_cache_lists';
		$sublists_key = 'newspack_nl_mailchimp_cache_aud1';

		update_option(
			$lists_key,
			[
				[
					'id'   => 'aud1',
					'name' => 'Audience 1',
				],
			]
		);
		delete_option( $sublists_key );
		$this->assertFalse(
			Newspack_Newsletters_Mailchimp_Cached_Data::filter_lists_complete( true ),
			'A cold sublist cache must veto completeness.'
		);

		update_option( $sublists_key, [ 'tags' => [] ] );
		$this->assertTrue(
			Newspack_Newsletters_Mailchimp_Cached_Data::filter_lists_complete( true ),
			'A warmed sublist cache must not veto an otherwise-complete result.'
		);

		delete_option( $lists_key );
		delete_option( $sublists_key );
	}

	/**
	 * GET /lists must advertise the warming header while sublists are cold and
	 * drop it once they are warm, so the admin UI knows when to poll.
	 */
	public function test_api_get_lists_sets_warming_header_until_warm() {
		$lists_key    = 'newspack_nl_mailchimp_cache_lists';
		$date_key     = 'newspack_nl_mailchimp_cache_date_lists';
		$audience_id  = 'audHDR';
		$sublists_key = 'newspack_nl_mailchimp_cache_' . $audience_id;
		$header       = Newspack_Newsletters_Subscription::LISTS_WARMING_HEADER;

		Newspack_Newsletters::set_service_provider( 'mailchimp' );
		update_option( 'newspack_mailchimp_api_key', 'test-us1' );
		// Simulate the completeness veto that Cached_Data::init() would register
		// (init only runs when the provider is mailchimp at bootstrap).
		add_filter(
			'newspack_newsletters_subscription_lists_complete',
			[ 'Newspack_Newsletters_Mailchimp_Cached_Data', 'filter_lists_complete' ]
		);

		// Fresh audiences cache (so get_lists() serves without a real API call),
		// per-list sublist cache left cold.
		update_option(
			$lists_key,
			[
				[
					'id'   => $audience_id,
					'name' => 'Header Audience',
				],
			]
		);
		update_option( $date_key, time() );
		delete_option( $sublists_key );
		delete_transient( 'newspack_newsletters_lists_mailchimp' );

		$cold = Newspack_Newsletters_Subscription::api_get_lists();
		$this->assertArrayHasKey( $header, $cold->get_headers(), 'Cold cache must set the warming header.' );

		// Warm the sublist cache.
		update_option(
			$sublists_key,
			[
				'segments'            => [],
				'interest_categories' => [],
				'tags'                => [],
				'folders'             => [],
				'merge_fields'        => [],
			]
		);
		delete_transient( 'newspack_newsletters_lists_mailchimp' );

		$warm = Newspack_Newsletters_Subscription::api_get_lists();
		$this->assertArrayNotHasKey( $header, $warm->get_headers(), 'Warm cache must not set the warming header.' );

		remove_filter(
			'newspack_newsletters_subscription_lists_complete',
			[ 'Newspack_Newsletters_Mailchimp_Cached_Data', 'filter_lists_complete' ]
		);
		delete_option( $lists_key );
		delete_option( $date_key );
		delete_option( $sublists_key );
		delete_option( 'newspack_mailchimp_api_key' );
		delete_transient( 'newspack_newsletters_lists_mailchimp' );
	}

	/**
	 * A still-warming (incomplete) result must still be cached — flagged
	 * incomplete — rather than vetoed, so repeated polls are served from cache
	 * instead of re-running the provider fetch; once warm it caches as complete.
	 */
	public function test_get_lists_caches_partial_snapshot() {
		$lists_key    = 'newspack_nl_mailchimp_cache_lists';
		$date_key     = 'newspack_nl_mailchimp_cache_date_lists';
		$audience_id  = 'audPARTIAL';
		$sublists_key = 'newspack_nl_mailchimp_cache_' . $audience_id;
		$cache_key    = 'newspack_newsletters_lists_mailchimp';

		Newspack_Newsletters::set_service_provider( 'mailchimp' );
		update_option( 'newspack_mailchimp_api_key', 'test-us1' );
		add_filter(
			'newspack_newsletters_subscription_lists_complete',
			[ 'Newspack_Newsletters_Mailchimp_Cached_Data', 'filter_lists_complete' ]
		);

		update_option(
			$lists_key,
			[
				[
					'id'   => $audience_id,
					'name' => 'Partial Audience',
				],
			]
		);
		update_option( $date_key, time() );
		delete_option( $sublists_key );
		delete_transient( $cache_key );

		// Cold: audiences returned, cached as a partial snapshot flagged incomplete.
		$lists = Newspack_Newsletters_Subscription::get_lists();
		$this->assertIsArray( $lists );
		$cached = get_transient( $cache_key );
		$this->assertIsArray( $cached, 'A still-warming result must still be cached (not vetoed).' );
		$this->assertArrayHasKey( 'complete', $cached );
		$this->assertFalse( $cached['complete'], 'The cached snapshot must be flagged incomplete while sublists are cold.' );

		// Warm + invalidate (as the cache-updated action does): recompose caches complete.
		update_option(
			$sublists_key,
			[
				'segments'            => [],
				'interest_categories' => [],
				'tags'                => [],
				'folders'             => [],
				'merge_fields'        => [],
			]
		);
		delete_transient( $cache_key );
		Newspack_Newsletters_Subscription::get_lists();
		$cached = get_transient( $cache_key );
		$this->assertIsArray( $cached );
		$this->assertTrue( $cached['complete'], 'Once sublists are warm the cached snapshot must be flagged complete.' );

		remove_filter(
			'newspack_newsletters_subscription_lists_complete',
			[ 'Newspack_Newsletters_Mailchimp_Cached_Data', 'filter_lists_complete' ]
		);
		delete_option( $lists_key );
		delete_option( $date_key );
		delete_option( $sublists_key );
		delete_option( 'newspack_mailchimp_api_key' );
		delete_transient( $cache_key );
	}

	/**
	 * Fetch failures must not surface an error while the provider has no API
	 * key configured (an unconfigured state, not a failure), but must once a
	 * key is present.
	 */
	public function test_maybe_add_error_requires_api_key() {
		delete_option( 'newspack_mailchimp_api_key' );
		delete_option( 'newspack_newsletters_mailchimp_api_key' );
		delete_option( 'newspack_nl_mailchimp_cache_errors' );

		$method = new ReflectionMethod( 'Newspack_Newsletters_Mailchimp_Cached_Data', 'maybe_add_error' );
		$method->setAccessible( true );

		// No key configured: the error is not recorded.
		$method->invoke( null, 'lists', 'Invalid MailChimp API key supplied.' );
		$this->assertFalse(
			get_option( 'newspack_nl_mailchimp_cache_errors' ),
			'No error should be stored while the provider has no API key.'
		);

		// Key present: a cold-cache fetch error is now surfaced.
		update_option( 'newspack_mailchimp_api_key', 'test-us1' );
		$method->invoke( null, 'lists', 'Invalid MailChimp API key supplied.' );
		$errors = get_option( 'newspack_nl_mailchimp_cache_errors' );
		$this->assertIsArray( $errors );
		$this->assertArrayHasKey( 'lists', $errors );

		delete_option( 'newspack_mailchimp_api_key' );
		delete_option( 'newspack_nl_mailchimp_cache_errors' );
	}
}
