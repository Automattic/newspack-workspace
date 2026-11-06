<?php
/**
 * Tests for the setup wizard's starter content initialization endpoint.
 *
 * @package Newspack\Tests
 */

/**
 * Setup wizard starter content init test case.
 *
 * @group setup-wizard
 * @covers \Newspack\Setup_Wizard
 */
class Setup_Wizard_Starter_Content_Init_Test extends WP_UnitTestCase {

	/**
	 * REST route of the endpoint.
	 *
	 * @var string
	 */
	const ROUTE = '/newspack/v1/wizard/newspack-setup-wizard/starter-content/init';

	/**
	 * Body served for the old site's posts.
	 *
	 * @var string
	 */
	private $posts_body = '[]';

	/**
	 * Setup.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		add_filter( 'pre_http_request', [ $this, 'mock_http' ], 10, 3 );
		add_filter( 'http_request_host_is_external', '__return_true' );
		do_action( 'rest_api_init' );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', [ $this, 'mock_http' ], 10 );
		remove_filter( 'http_request_host_is_external', '__return_true' );
		delete_option( '_newspack_starter_content_approach' );
		delete_option( '_newspack_existing_content_raw_data' );
		parent::tear_down();
	}

	/**
	 * Serve the old site's posts.
	 *
	 * @param false|array $pre  Preempt.
	 * @param array       $args Args.
	 * @param string      $url  URL.
	 * @return array
	 */
	public function mock_http( $pre, $args, $url ) {
		$is_posts = 'http://localhost/wp-json/wp/v2/posts?per_page=40' === $url;
		return [
			'headers'  => [ 'content-type' => 'application/json' ],
			'body'     => $is_posts ? $this->posts_body : '{}',
			'response' => [
				'code'    => $is_posts ? 200 : 404,
				'message' => '',
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	/**
	 * A fake post as the WordPress REST API returns it.
	 *
	 * @param int $index Post index.
	 * @return array
	 */
	private function remote_post( $index ) {
		return [
			'title'          => [ 'rendered' => 'Post ' . $index ],
			'date'           => '2026-01-01T00:00:00',
			'content'        => [ 'rendered' => '<p>Body</p>' ],
			'featured_media' => 0,
		];
	}

	/**
	 * Initialize the starter content.
	 *
	 * @param array $params Body params.
	 * @return WP_REST_Response
	 */
	private function init( $params ) {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A move reports how many articles the old site returned.
	 */
	public function test_import_reports_the_number_of_articles_found() {
		$this->posts_body = wp_json_encode( array_map( [ $this, 'remote_post' ], range( 1, 7 ) ) );
		$data             = $this->init(
			[
				'type' => 'import',
				'site' => 'http://localhost/',
			]
		)->get_data();
		$this->assertSame( 7, $data['post_count'] );
	}

	/**
	 * A move from a site with no articles reports zero.
	 */
	public function test_import_reports_zero_when_the_site_has_no_articles() {
		$data = $this->init(
			[
				'type' => 'import',
				'site' => 'http://localhost/',
			]
		)->get_data();
		$this->assertSame( 0, $data['post_count'] );
	}

	/**
	 * Generated content has no article count.
	 */
	public function test_generated_has_no_article_count() {
		$data = $this->init( [ 'type' => 'generated' ] )->get_data();
		$this->assertArrayNotHasKey( 'post_count', $data );
	}
}
