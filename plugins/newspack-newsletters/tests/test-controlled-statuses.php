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
