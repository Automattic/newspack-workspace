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
	 * Test that the npnl param is forwarded through the click proxy redirect.
	 */
	public function test_handle_click_propagates_npnl_param() {
		$content = "<!-- wp:paragraph -->\n<p><a href=\"https://google.com/article/\">Link</a><\/p>\n<!-- \/wp:paragraph -->";
		$post_id = $this->factory->post->create(
			[
				'post_type'    => Ads::CPT,
				'post_title'   => 'Ad with npnl test link.',
				'post_content' => $content,
			]
		);
		update_post_meta( $post_id, 'newspack_email_html', $content );

		// Capture the final redirect URL via the tracking action. Store the
		// callback in a variable so we can remove_action() it precisely at
		// the end of the test and avoid leaking into subsequent tests.
		$captured_url     = null;
		$capture_callback = function( $newsletter_id, $email_address, $url ) use ( &$captured_url ) {
			$captured_url = $url;
		};
		add_action( 'newspack_newsletters_tracking_click', $capture_callback, 10, 3 );

		$_GET['np_newsletters_click']              = 1;
		$_GET['id']                                = $post_id;
		$_GET['em']                                = 'reader@example.com';
		$_GET['url']                               = 'https://google.com/article/';
		$_GET[ Click::FORWARDED_NPNL_PARAM ]       = 'fake-token-string';
		Click::handle_click( false );

		// Verify the npnl param was appended to the destination URL.
		$this->assertNotNull( $captured_url, 'Tracking action should have fired.' );
		$parsed = \wp_parse_url( $captured_url );
		\wp_parse_str( $parsed['query'] ?? '', $query_args );
		$this->assertArrayHasKey( Click::FORWARDED_NPNL_PARAM, $query_args, 'npnl param should be present in the proxied URL.' );
		$this->assertEquals( 'fake-token-string', $query_args[ Click::FORWARDED_NPNL_PARAM ] );

		// Clean up.
		remove_action( 'newspack_newsletters_tracking_click', $capture_callback, 10 );
		unset( $_GET['np_newsletters_click'], $_GET['id'], $_GET['em'], $_GET['url'], $_GET[ Click::FORWARDED_NPNL_PARAM ] );
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
	 * Run the click handler for a destination that should be turned away, and
	 * assert it was.
	 *
	 * @param int    $ad_id   Ad post ID to attribute the click to.
	 * @param string $url     Destination URL.
	 * @param string $message Assertion message.
	 */
	private function assert_click_destination_rejected( $ad_id, $url, $message ) {
		$_GET['np_newsletters_click'] = 1;
		$_GET['id']                   = $ad_id;
		$_GET['url']                  = $url;

		$rejected = false;
		try {
			Click::handle_click( false );
		} catch ( \Throwable $th ) {
			$rejected = true;
			$this->assertEquals( 'Invalid URL', $th->getMessage() );
			$this->assertEquals( 400, $th->getCode() );
		} finally {
			unset( $_GET['np_newsletters_click'], $_GET['id'], $_GET['url'] );
		}

		$this->assertTrue( $rejected, $message );
	}

	/**
	 * Create an ad whose rendered email holds a single external link.
	 *
	 * @return int Ad post ID.
	 */
	private function create_ad_with_link() {
		$content = "<!-- wp:paragraph -->\n<p><a href=\"https://google.com/article/\">Link</a><\/p>\n<!-- \/wp:paragraph -->";
		$ad_id   = $this->factory->post->create(
			[
				'post_type'    => Ads::CPT,
				'post_title'   => 'Ad with a link.',
				'post_content' => $content,
			]
		);
		update_post_meta( $ad_id, 'newspack_email_html', $content );
		return $ad_id;
	}

	/**
	 * Only a destination on the site's own host counts as same-site.
	 */
	public function test_is_same_site_url() {
		$site_host = \wp_parse_url( \home_url(), PHP_URL_HOST );

		$this->assertTrue( Click::is_same_site_url( \home_url( '/' ) ) );
		$this->assertTrue( Click::is_same_site_url( \home_url( '/some/page/?with=args' ) ) );
		$this->assertTrue( Click::is_same_site_url( 'https://' . $site_host . '/page/' ), 'The scheme should not decide the answer.' );
		$this->assertTrue( Click::is_same_site_url( 'http://' . strtoupper( $site_host ) . '/page/' ), 'Host comparison should be case-insensitive.' );

		$not_the_site = [
			[ 'https://' . $site_host . '.elsewhere.test/', 'a host that only starts with the site host' ],
			[ 'https://elsewhere.test/' . \home_url( '/page/' ), 'the site URL sitting in the path' ],
			[ 'https://' . $site_host . '@elsewhere.test/', 'the site URL sitting in the userinfo' ],
			[ 'https://sub.' . $site_host . '/page/', 'a subdomain of the site host' ],
			[ '/a/relative/path/', 'a URL with no host at all' ],
			[ '', 'an empty URL' ],
		];
		foreach ( $not_the_site as [ $url, $description ] ) {
			$this->assertFalse( Click::is_same_site_url( $url ), "Should not treat $description as same-site." );
		}
	}

	/**
	 * The click handler applies that classification: a destination that merely
	 * mentions the site URL still has to be found in the ad's email.
	 */
	public function test_handle_click_rejects_destination_that_only_mentions_the_site() {
		$this->assert_click_destination_rejected(
			$this->create_ad_with_link(),
			'https://google.com/' . \home_url( '/page/' ),
			'A destination that only mentions the site URL should not skip the ad check.'
		);
	}

	/**
	 * A destination on the site itself is still allowed through without appearing
	 * in the ad's email.
	 */
	public function test_handle_click_allows_same_site_destination() {
		$ad_id = $this->create_ad_with_link();

		$_GET['np_newsletters_click'] = 1;
		$_GET['id']                   = $ad_id;
		$_GET['em']                   = 'reader@example.com';
		$_GET['url']                  = \home_url( '/a-page-not-in-the-ad/' );

		Click::handle_click( false );

		$this->assertEquals( 1, \get_post_meta( $ad_id, 'tracking_clicks', true ), 'A same-site destination should be tracked and allowed.' );

		unset( $_GET['np_newsletters_click'], $_GET['id'], $_GET['em'], $_GET['url'] );
	}

	/**
	 * A verified destination is redirected to as written, whatever case its host
	 * carries.
	 *
	 * The host parsed out of the location is matched against the allowed list with a
	 * strict in_array(), so an allowance registered in one case doesn't cover a
	 * destination written in another — the reader would land on the fallback instead
	 * of the link they clicked.
	 */
	public function test_redirect_location_keeps_destination_whatever_the_host_case() {
		$cases = [
			'https://example.com/article/' => 'a lowercase host',
			'https://Example.com/article/' => 'a capitalised host',
			'https://EXAMPLE.COM/article/' => 'an uppercase host',
			'https://ExAmPlE.com/a?b=c#d'  => 'a mixed-case host with a query and fragment',
		];
		foreach ( $cases as $url => $description ) {
			$this->assertSame(
				$url,
				Click::get_redirect_location( $url ),
				"A destination with $description should be redirected to as written."
			);
		}
	}

	/**
	 * A location that can't be validated falls back to the site's front page, rather
	 * than the wp-admin default a logged-out reader can't use.
	 *
	 * Defence in depth rather than a path a click can take: handle_click() rejects
	 * anything wp_http_validate_url() turns down, and what survives that always has
	 * an http(s) scheme and a host, which this method then allows. The fallback is
	 * pinned here so it stays correct if that ordering ever changes.
	 */
	public function test_redirect_location_falls_back_to_home() {
		$this->assertSame( \home_url(), Click::get_redirect_location( 'javascript:alert(1)' ) );
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

	/**
	 * Test logs processing – a stale event must not abort the rest of the batch.
	 */
	public function test_process_logs_skips_mismatched_tracking_id() {
		$stale_newsletter_id = $this->factory->post->create( [ 'post_type' => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT ] );
		$newsletter_id       = $this->factory->post->create( [ 'post_type' => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT ] );
		update_post_meta( $stale_newsletter_id, 'tracking_id', 'tracking_id_current' );
		update_post_meta( $newsletter_id, 'tracking_id', 'tracking_id_2' );

		// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions
		// A stale event (old tracking ID) first, then valid events for another newsletter.
		$log_file_path = tempnam( sys_get_temp_dir(), 'newspack_newsletters_pixel_log_' );
		file_put_contents( $log_file_path, "$stale_newsletter_id|tracking_id_old|email_1@example.com" . PHP_EOL );
		file_put_contents( $log_file_path, "$newsletter_id|tracking_id_2|email_2@example.com" . PHP_EOL, FILE_APPEND );
		file_put_contents( $log_file_path, "$newsletter_id|tracking_id_2|email_3@example.com" . PHP_EOL, FILE_APPEND );
		update_option( 'newspack_newsletters_tracking_pixel_log_file', $log_file_path );

		Pixel::process_logs();

		// The stale event is not counted.
		$this->assertEmpty( get_post_meta( $stale_newsletter_id, 'tracking_pixel_seen', true ) );
		// The events after it still are.
		$this->assertEquals( 2, get_post_meta( $newsletter_id, 'tracking_pixel_seen', true ) );

		// Clean up.
		unlink( get_option( 'newspack_newsletters_tracking_pixel_log_file' ) );
		// phpcs:enable WordPressVIPMinimum.Functions.RestrictedFunctions
	}

	/**
	 * Scheduling the log processing event respects the tracking setting.
	 */
	public function test_schedule_log_processing_respects_tracking_setting() {
		wp_clear_scheduled_hook( 'newspack_newsletters_tracking_pixel_process_log' );

		update_option( 'newspack_newsletters_use_tracking_pixel', 0 );
		Pixel::schedule_log_processing();
		$this->assertFalse(
			wp_next_scheduled( 'newspack_newsletters_tracking_pixel_process_log' ),
			'No log processing should be scheduled while pixel tracking is off.'
		);

		update_option( 'newspack_newsletters_use_tracking_pixel', 1 );
		Pixel::schedule_log_processing();
		$this->assertNotFalse(
			wp_next_scheduled( 'newspack_newsletters_tracking_pixel_process_log' ),
			'Log processing should be scheduled while pixel tracking is on.'
		);

		// Clean up.
		wp_clear_scheduled_hook( 'newspack_newsletters_tracking_pixel_process_log' );
	}

	/**
	 * Turning pixel tracking off removes the standalone pixel file, its logs and
	 * the scheduled processing, so nothing keeps logging into a file nothing reads.
	 */
	public function test_disabling_pixel_tracking_tears_down_pixel_machinery() {
		update_option( 'newspack_newsletters_use_tracking_pixel', 1 );
		Pixel::process_logs(); // Bootstraps the standalone pixel file and log file.

		$pixel_file = WP_CONTENT_DIR . '/np-newsletters-pixel.php';
		$this->assertFileExists( $pixel_file );
		$this->assertNotEmpty( get_option( 'newspack_newsletters_tracking_pixel_log_file' ) );

		update_option( 'newspack_newsletters_use_tracking_pixel', 0 );

		$this->assertFileDoesNotExist( $pixel_file );
		$this->assertFalse( get_option( 'newspack_newsletters_tracking_pixel_log_file' ) );
		$this->assertFalse( wp_next_scheduled( 'newspack_newsletters_tracking_pixel_process_log' ) );
	}

	/**
	 * Teardown only deletes files that look like pixel logs: a corrupted option
	 * must not turn disabling tracking into deleting an arbitrary path.
	 */
	public function test_teardown_ignores_non_log_paths_in_options() {
		update_option( 'newspack_newsletters_use_tracking_pixel', 1 );

		// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions
		$unrelated = tempnam( sys_get_temp_dir(), 'unrelated_' );
		update_option( 'newspack_newsletters_tracking_pixel_log_file', $unrelated );

		update_option( 'newspack_newsletters_use_tracking_pixel', 0 );

		$this->assertFileExists( $unrelated, 'A path that is not a pixel log must not be deleted.' );
		$this->assertFalse( get_option( 'newspack_newsletters_tracking_pixel_log_file' ), 'The option is still cleared.' );

		// Clean up.
		unlink( $unrelated );
		// phpcs:enable WordPressVIPMinimum.Functions.RestrictedFunctions
	}
}
