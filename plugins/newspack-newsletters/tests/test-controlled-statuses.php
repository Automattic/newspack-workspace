<?php
/**
 * Class Test Controlled Statuses
 *
 * @package Newspack_Newsletters
 */

/**
 * Controlled Statuses Test.
 */
class Newsletter_Controlled_Statuses_Test extends WP_UnitTestCase {
	/**
	 * Test set up.
	 */
	public function set_up() {
		// Set an ESP.
		\Newspack_Newsletters::set_service_provider( 'mailchimp' );
	}

	/**
	 * Test that publishing a newsletter without 'is_public' makes it private.
	 */
	public function test_publish_private_newsletter() {
		// Create draft.
		$post_id = self::factory()->post->create(
			[
				'post_type'   => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status' => 'draft',
			]
		);
		// Set newsletter as sent.
		\Newspack_Newsletters::set_newsletter_sent( $post_id );
		// Publish newsletter.
		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'publish',
			]
		);
		// Assert published newsletter is private.
		$result_post = get_post( $post_id );
		$this->assertEquals( 'private', $result_post->post_status );
	}

	/**
	 * Test that publishing a newsletter with 'is_public' makes it public.
	 */
	public function test_publish_public_newsletter() {
		// Create draft.
		$post_id = self::factory()->post->create(
			[
				'post_type'   => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status' => 'draft',
			]
		);
		// Add 'is_public' meta.
		update_post_meta( $post_id, 'is_public', true );
		// Set newsletter as sent.
		\Newspack_Newsletters::set_newsletter_sent( $post_id );
		// Publish newsletter.
		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'publish',
			]
		);
		// Assert published newsletter is publish.
		$result_post = get_post( $post_id );
		$this->assertEquals( 'publish', $result_post->post_status );
	}

	/**
	 * The front-end refresh/exit in fix_public_status() must be suppressed on
	 * programmatic requests (REST, AJAX, cron, WP-CLI, XML-RPC) and feed
	 * renders, where an exit would truncate the response mid-flight. The REST
	 * branch — the actual regression this fixes — is pinned separately in
	 * test_public_status_refresh_suppressed_during_rest(); the WP_CLI and
	 * XMLRPC_REQUEST branches hinge on `define()` constants and share this same
	 * guard, so the filter-toggleable AJAX/cron/feed branches cover them here.
	 */
	public function test_public_status_refresh_suppressed_off_page() {
		$is_front_end = new ReflectionMethod( 'Newspack_Newsletters', 'is_front_end_page_request' );
		$is_front_end->setAccessible( true );

		// A plain (non-admin) context reads as a front-end page request.
		$this->assertTrue( $is_front_end->invoke( null ), 'A plain request should be treated as a page view.' );

		// AJAX must suppress the refresh.
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->assertFalse( $is_front_end->invoke( null ), 'Refresh must be suppressed during AJAX.' );
		remove_filter( 'wp_doing_ajax', '__return_true' );

		// Cron must suppress the refresh.
		add_filter( 'wp_doing_cron', '__return_true' );
		$this->assertFalse( $is_front_end->invoke( null ), 'Refresh must be suppressed during cron.' );
		remove_filter( 'wp_doing_cron', '__return_true' );

		// A feed render must suppress the refresh (an exit would truncate the XML).
		global $wp_query;
		$wp_query->is_feed = true;
		$this->assertFalse( $is_front_end->invoke( null ), 'Refresh must be suppressed during a feed render.' );
		$wp_query->is_feed = false;
	}

	/**
	 * Pins the REST branch — the exact regression this fix addresses — in CI.
	 * `REST_REQUEST` is a `define()` constant, so this runs in a separate
	 * process to set it without leaking the constant into other tests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_public_status_refresh_suppressed_during_rest() {
		define( 'REST_REQUEST', true );
		$is_front_end = new ReflectionMethod( 'Newspack_Newsletters', 'is_front_end_page_request' );
		$is_front_end->setAccessible( true );
		$this->assertFalse( $is_front_end->invoke( null ), 'Refresh must be suppressed during a REST request.' );
	}

	/**
	 * Corrects an invalid publish + non-public newsletter to private on a
	 * programmatic request (via fix_public_status()), without exiting.
	 */
	public function test_fix_public_status_corrects_without_exit_off_page() {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status' => 'draft',
			]
		);
		\Newspack_Newsletters::set_newsletter_sent( $post_id );

		// Force the invalid publish + non-public state directly, bypassing the
		// publish-time guard, so fix_public_status() has something to correct.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup forces an invalid state that the publish-time guard would otherwise correct.
		$wpdb->update( $wpdb->posts, [ 'post_status' => 'publish' ], [ 'ID' => $post_id ] );
		clean_post_cache( $post_id );

		// Simulate a cron request so the redirect/exit path is not taken; before
		// the guard fix this call would `exit` and abort the test run.
		add_filter( 'wp_doing_cron', '__return_true' );
		\Newspack_Newsletters::fix_public_status( get_post( $post_id ) );
		remove_filter( 'wp_doing_cron', '__return_true' );

		$this->assertEquals( 'private', get_post( $post_id )->post_status, 'A non-public newsletter should be corrected to private.' );
	}

	/**
	 * Test that is_newsletter_sent handles valid and invalid publish dates correctly.
	 */
	public function test_is_newsletter_sent_with_invalid_date() {
		global $wpdb;

		// Create a newsletter post with a valid post date.
		$post_id = self::factory()->post->create(
			[
				'post_type'   => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status' => 'draft',
				'post_date'   => '2024-01-15 10:00:00',
			]
		);
		$result = \Newspack_Newsletters::is_newsletter_sent( $post_id );
		$this->assertFalse( $result, 'Should return false for a draft post' );

		// Publish the post.
		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'publish',
			]
		);

		// Test that the function returns a valid timestamp with valid publish date.
		$result = \Newspack_Newsletters::is_newsletter_sent( $post_id );
		$this->assertNotFalse( $result, 'Should return a timestamp for a published post with valid date' );
		$this->assertIsInt( $result, 'Should return an integer timestamp' );
		$this->assertGreaterThan( 0, $result, 'Timestamp should be greater than 0' );

		// Verify the timestamp matches the publish date.
		$post_datetime = get_post_datetime( $post_id, 'date', 'gmt' );
		$expected_timestamp = $post_datetime->getTimestamp();
		$this->assertEquals( $expected_timestamp, $result, 'Should return the publish date timestamp' );

		// Update the post date to an invalid value using direct database update
		// to bypass WordPress validation that might prevent setting invalid dates.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$wpdb->posts,
			[
				'post_date'     => '0000-00-00 00:00:00',
				'post_date_gmt' => '0000-00-00 00:00:00',
			],
			[ 'ID' => $post_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
		clean_post_cache( $post_id );

		$result = \Newspack_Newsletters::is_newsletter_sent( $post_id );

		// Assert that the function returns false for invalid date.
		$this->assertFalse( $result, 'Should return false for post with invalid publish date' );
	}
}
