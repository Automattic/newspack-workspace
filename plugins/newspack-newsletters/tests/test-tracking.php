<?php
/**
 * Test Tracking.
 *
 * @package Newspack_Newsletters
 */

use Newspack_Newsletters\Tracking\Pixel;
use Newspack_Newsletters\Tracking\Click;
use Newspack_Newsletters\Ads;

/**
 * Newsletters Tracking Test.
 */
class Newsletters_Tracking_Test extends WP_UnitTestCase {
	/**
	 * Test tracking pixel.
	 */
	public function test_tracking_pixel() {
		$post_id = $this->factory->post->create( [ 'post_type' => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT ] );
		$post    = \get_post( $post_id );
		ob_start();
		do_action( 'newspack_newsletters_editor_mjml_body', $post );
		$mjml_body = ob_get_clean();
		$this->assertMatchesRegularExpression( '/\/np-newsletters.pixel.php\?id=' . $post_id . '/', $mjml_body );

		// Fetch the tracking pixel URL from body.
		$pattern = '/src="([^"]*np-newsletters-pixel.php[^"]*)"/i';
		$matches = [];
		preg_match( $pattern, $mjml_body, $matches );
		$pixel_url  = html_entity_decode( $matches[1] );
		$parsed_url = \wp_parse_url( $pixel_url );
		$args       = \wp_parse_args( $parsed_url['query'] );

		$this->assertEquals( $post_id, intval( $args['id'] ) );
		$this->assertEquals( get_post_meta( $post_id, 'tracking_id', true ), $args['tid'] );
		$this->assertArrayHasKey( 'em', $args );

		// Call the tracking pixel.
		Pixel::track_seen( $args['id'], $args['tid'], 'fake@email.com' );

		// Assert seen once.
		$seen = \get_post_meta( $post_id, 'tracking_pixel_seen', true );
		$this->assertEquals( 1, $seen );

		// Call the tracking pixel again.
		Pixel::track_seen( $args['id'], $args['tid'], 'fake@email.com' );

		// Assert seen twice.
		$seen = \get_post_meta( $post_id, 'tracking_pixel_seen', true );
		$this->assertEquals( 2, $seen );
	}

	/**
	 * Test tracking click.
	 */
	public function test_tracking_click() {
		$content  = "<!-- wp:paragraph -->\n<p><a href=\"https://google.com\">Link</a><\/p>\n<!-- \/wp:paragraph -->";
		$post_id  = $this->factory->post->create(
			[
				'post_type'    => Ads::CPT,
				'post_title'   => 'A newsletter ad with link.',
				'post_content' => $content,
			]
		);

		// Ensure the newspack_email_html meta is set.
		update_post_meta( $post_id, 'newspack_email_html', $content );

		$post     = \get_post( $post_id );
		$rendered = Newspack_Newsletters_Renderer::post_to_mjml_components( $post );

		// Fetch the link URL from body.
		$pattern = '/href="([^"]*)"/i';
		$matches = [];
		preg_match( $pattern, $rendered, $matches );
		$link_url   = $matches[1];
		$parsed_url = \wp_parse_url( $link_url );
		$args       = \wp_parse_args( $parsed_url['query'] );

		$this->assertEquals( $post_id, intval( $args['id'] ) );
		$this->assertArrayHasKey( 'em', $args );

		$parsed_destination_url = \wp_parse_url( $args['url'] );
		$this->assertEquals( 'https', $parsed_destination_url['scheme'] );
		$this->assertEquals( 'google.com', $parsed_destination_url['host'] );

		// Trigger the click handled.
		$_GET['np_newsletters_click'] = 1;
		$_GET['id'] = $post_id;
		$_GET['em'] = 'fake@email.com';
		$_GET['url'] = $args['url'];
		Click::handle_click( false );

		// Assert clicked once.
		$clicks = \get_post_meta( $post_id, 'tracking_clicks', true );
		$this->assertEquals( 1, $clicks );

		// Trigger the click handled again.
		Click::handle_click( false );

		// Assert clicked twice.
		$clicks = \get_post_meta( $post_id, 'tracking_clicks', true );
		$this->assertEquals( 2, $clicks );
		$post_id = $this->factory->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_title'   => 'A newsletter with link.',
				'post_content' => $content,
			]
		);
		// Ensure the newspack_email_html meta is set.
		update_post_meta( $post_id, 'newspack_email_html', $content );

		$post     = \get_post( $post_id );
		$rendered = Newspack_Newsletters_Renderer::post_to_mjml_components( $post );

		// Fetch the link URL from body.
		$pattern = '/href="([^"]*)"/i';
		$matches = [];
		preg_match( $pattern, $rendered, $matches );
		$link_url   = $matches[1];
		$parsed_url = \wp_parse_url( $link_url );
		$args       = \wp_parse_args( $parsed_url['query'] );

		// Ensure id, url, em, and np_newsletters_click are NOT present.
		$this->assertArrayNotHasKey( 'id', $args );
		$this->assertArrayNotHasKey( 'url', $args );
		$this->assertArrayNotHasKey( 'em', $args );
		$this->assertArrayNotHasKey( 'np_newsletters_click', $args );
	}

	/**
	 * Test click tracking with a link that was not included in the newsletter.
	 */
	public function test_tracking_click_not_in_newsletter() {
		$post_id = $this->factory->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_title'   => 'A newsletter with link.',
				'post_content' => "<!-- wp:paragraph -->\n<p><a href=\"https://google.com\">Link</a><\/p>\n<!-- \/wp:paragraph -->",
			]
		);

		$_GET['np_newsletters_click'] = 1;
		$_GET['id'] = $post_id;
		$_GET['url'] = 'https://mischievous.com';
		try {
			Click::handle_click( false );
		} catch ( \Throwable $th ) {
			$this->assertEquals( 'Invalid URL', $th->getMessage() );
			$this->assertEquals( 400, $th->getCode() );
		}
	}

	/**
	 * Test logs processing.
	 */
	public function test_process_logs() {
		$newsletter_id = $this->factory->post->create( [ 'post_type' => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT ] );
		$tracking_id = 'tracking_id_1';
		update_post_meta( $newsletter_id, 'tracking_id', $tracking_id );

		// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions
		// Create a temporary log file.
		$log_file_path = tempnam( sys_get_temp_dir(), 'newspack_newsletters_pixel_log_' );
		file_put_contents( $log_file_path, "$newsletter_id|$tracking_id|email_1@example.com" . PHP_EOL );
		file_put_contents( $log_file_path, "$newsletter_id|$tracking_id|email_2@example.com" . PHP_EOL, FILE_APPEND );
		update_option( 'newspack_newsletters_tracking_pixel_log_file', $log_file_path );

		Pixel::process_logs();

		// Check that the log file has been removed.
		$this->assertFileDoesNotExist( $log_file_path );

		// Check that a new log file has been created.
		$new_log_file_path = get_option( 'newspack_newsletters_tracking_pixel_log_file' );
		$this->assertFileExists( $new_log_file_path );

		// Check that the log entries have been processed.
		$this->assertEquals( 2, get_post_meta( $newsletter_id, 'tracking_pixel_seen', true ) );

		// Clean up.
		unlink( $new_log_file_path );
		// phpcs:enable WordPressVIPMinimum.Functions.RestrictedFunctions
	}

	/**
	 * Test logs processing – log file length equals the max lines processed.
	 */
	public function test_process_logs_max_lines() {
		$newsletter_id = $this->factory->post->create( [ 'post_type' => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT ] );
		$tracking_id = 'tracking_id_1';
		update_post_meta( $newsletter_id, 'tracking_id', $tracking_id );

		// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions
		// Create a temporary log file.
		$log_file_path = tempnam( sys_get_temp_dir(), 'newspack_newsletters_pixel_log_' );
		file_put_contents( $log_file_path, "$newsletter_id|$tracking_id|email_1@example.com" . PHP_EOL );
		file_put_contents( $log_file_path, "$newsletter_id|$tracking_id|email_2@example.com" . PHP_EOL, FILE_APPEND );
		file_put_contents( $log_file_path, "$newsletter_id|$tracking_id|email_3@example.com" . PHP_EOL, FILE_APPEND );
		update_option( 'newspack_newsletters_tracking_pixel_log_file', $log_file_path );

		Pixel::process_logs( 3 ); // 3 lines at a time - exactly as many as there are log entries.

		// Check that the log entries have been processed.
		$this->assertEquals( 3, get_post_meta( $newsletter_id, 'tracking_pixel_seen', true ) );

		// Clean up.
		unlink( get_option( 'newspack_newsletters_tracking_pixel_log_file' ) );
		// phpcs:enable WordPressVIPMinimum.Functions.RestrictedFunctions
	}

	/**
	 * Test logs processing – log file length is longer than the max lines processed.
	 */
	public function test_process_logs_max_lines_more() {
		$newsletter_id = $this->factory->post->create( [ 'post_type' => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT ] );
		$tracking_id = 'tracking_id_1';
		update_post_meta( $newsletter_id, 'tracking_id', $tracking_id );

		// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions
		// Create a temporary log file.
		$log_file_path = tempnam( sys_get_temp_dir(), 'newspack_newsletters_pixel_log_' );
		file_put_contents( $log_file_path, "$newsletter_id|$tracking_id|email_1@example.com" . PHP_EOL );
		file_put_contents( $log_file_path, "$newsletter_id|$tracking_id|email_2@example.com" . PHP_EOL, FILE_APPEND );
		file_put_contents( $log_file_path, "$newsletter_id|$tracking_id|email_3@example.com" . PHP_EOL, FILE_APPEND );
		file_put_contents( $log_file_path, "$newsletter_id|$tracking_id|email_4@example.com" . PHP_EOL, FILE_APPEND );
		file_put_contents( $log_file_path, "$newsletter_id|$tracking_id|email_5@example.com" . PHP_EOL, FILE_APPEND );
		update_option( 'newspack_newsletters_tracking_pixel_log_file', $log_file_path );

		Pixel::process_logs( 2 ); // 2 entries at a time.

		$this->assertFileExists( $log_file_path, 'the log file should have not been removed just yet' );

		// Check that the log entries have been processed.
		$this->assertEquals( 2, get_post_meta( $newsletter_id, 'tracking_pixel_seen', true ) );


		Pixel::process_logs(); // Process the remaining 3 log lines.

		// Check that the log entries have been processed.
		$this->assertEquals( 5, get_post_meta( $newsletter_id, 'tracking_pixel_seen', true ) );

		$this->assertFileDoesNotExist( $log_file_path, 'the log file should have been removed' );

		// Clean up.
		unlink( get_option( 'newspack_newsletters_tracking_pixel_log_file' ) );
		// phpcs:enable WordPressVIPMinimum.Functions.RestrictedFunctions
	}

	/**
	 * Test logs processing – test ads hooks.
	 */
	public function test_process_logs_ads() {
		$newsletter_id = $this->factory->post->create( [ 'post_type' => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT ] );
		$ad_id = $this->factory->post->create( [ 'post_type' => Newspack_Newsletters\Ads::CPT ] );
		$tracking_id = 'tracking_id_1';
		update_post_meta( $newsletter_id, 'tracking_id', $tracking_id );

		Newspack_Newsletters\Ads::mark_ad_inserted( $newsletter_id, $ad_id );

		// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions
		// Create a temporary log file.
		$log_file_path = tempnam( sys_get_temp_dir(), 'newspack_newsletters_pixel_log_' );
		file_put_contents( $log_file_path, "$newsletter_id|$tracking_id|email_1@example.com" . PHP_EOL );
		file_put_contents( $log_file_path, "$newsletter_id|$tracking_id|email_2@example.com" . PHP_EOL, FILE_APPEND );
		file_put_contents( $log_file_path, "$newsletter_id|$tracking_id|email_3@example.com" . PHP_EOL, FILE_APPEND );
		update_option( 'newspack_newsletters_tracking_pixel_log_file', $log_file_path );

		Pixel::process_logs();

		// Check that the log entries have been processed.
		$this->assertEquals( 3, get_post_meta( $ad_id, 'tracking_impressions', true ) );

		// Clean up.
		unlink( get_option( 'newspack_newsletters_tracking_pixel_log_file' ) );
		// phpcs:enable WordPressVIPMinimum.Functions.RestrictedFunctions
	}
}
