<?php
/**
 * Test Newsletter Service Provider class.
 *
 * @package Newspack_Newsletters
 */

/**
 * Newsletters Service Provider class Test.
 */
class Newsletters_Newsletter_Service_Provider_Test extends WP_UnitTestCase {
	/**
	 * Test set up.
	 */
	public function set_up() {
		// Set an ESP.
		\Newspack_Newsletters::set_service_provider( 'mailchimp' );
		// Ensure the API key is not set (might be set by a different test).
		delete_option( 'newspack_mailchimp_api_key' );

		add_filter(
			'wp_die_handler',
			function() {
				return 'handle_wpdie_in_tests';}
		);
	}

	/**
	 * Test sending a newsletter.
	 */
	public function test_service_provider_send_newsletter_unconfigured() {
		// Create a draft and then publish, to trigger a sending.
		$post_id = self::factory()->post->create(
			[
				'post_type'   => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status' => 'draft',
			]
		);

		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'Missing or invalid Mailchimp credentials.' );

		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'publish',
			]
		);
	}
}
