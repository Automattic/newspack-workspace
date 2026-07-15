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
				[ 'Newspack_Newsletters_Subscription', 'clear_lists_cache' ]
			),
			'clear_lists_cache should be hooked to the Mailchimp cache-updated action.'
		);

		$cache_key = 'newspack_newsletters_lists_mailchimp';
		set_transient( $cache_key, [ [ 'id' => 'audience-only' ] ], HOUR_IN_SECONDS );
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
}
