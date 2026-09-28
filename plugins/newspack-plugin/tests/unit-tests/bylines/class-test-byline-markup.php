<?php
/**
 * Test the markup allowed in custom bylines.
 *
 * @package Newspack\Tests
 */

namespace Newspack\Tests;

use WP_UnitTestCase;
use WP_REST_Request;
use Newspack\Bylines;

/**
 * Custom bylines keep the post-content allowlist on save, in REST responses and in the byline HTML.
 */
class Test_Byline_Markup extends WP_UnitTestCase {

	/**
	 * Byline author.
	 *
	 * @var int
	 */
	private int $author_id;

	/**
	 * Post carrying the byline.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Register the meta and create a post with an active byline.
	 */
	public function setUp(): void {
		parent::setUp();
		global $wp_rest_server;
		$wp_rest_server = null;
		Bylines::register_post_meta();
		$this->author_id = $this->factory->user->create(
			[
				'role'         => 'author',
				'display_name' => 'Jane Doe',
			]
		);
		$this->post_id   = $this->factory->post->create();
		update_post_meta( $this->post_id, Bylines::META_KEY_ACTIVE, true );
	}

	/**
	 * Reset the REST server.
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tearDown();
	}

	/**
	 * A byline with allowed formatting, an author token, and markup outside the allowlist.
	 */
	private function byline(): string {
		return '<em>By</em> [Author id=' . $this->author_id . ']J. Doe[/Author]'
			. '<iframe src="https://example.test/"></iframe>'
			. '<a href="https://example.test/" ping="https://example.test/p">site</a>';
	}

	/**
	 * Store a byline without going through the meta API, as older values were stored.
	 *
	 * @param string $value Byline.
	 */
	private function store_raw( string $value ): void {
		global $wpdb;
		$row = [
			'post_id'    => $this->post_id,
			'meta_key'   => Bylines::META_KEY_BYLINE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		];
		// Bypasses the meta API on purpose, so the value skips the registered sanitize callback.
		$wpdb->insert( $wpdb->postmeta, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		wp_cache_delete( $this->post_id, 'post_meta' );
	}

	/**
	 * Assert a byline kept its formatting and author token and lost the rest.
	 *
	 * @param string $byline  Byline to check.
	 * @param string $context Assertion context.
	 */
	private function assert_allowlisted( string $byline, string $context ): void {
		$this->assertStringContainsString( '<em>By</em>', $byline, $context );
		$this->assertStringNotContainsString( '<iframe', $byline, $context );
		$this->assertStringNotContainsString( 'ping=', $byline, $context );
	}

	/**
	 * Saving a byline limits it to the post allowlist and keeps author tokens.
	 */
	public function test_saved_byline_is_limited_to_the_post_allowlist() {
		update_post_meta( $this->post_id, Bylines::META_KEY_BYLINE, $this->byline() );
		$stored = get_post_meta( $this->post_id, Bylines::META_KEY_BYLINE, true );
		$this->assert_allowlisted( $stored, 'stored' );
		$this->assertStringContainsString( '[Author id=' . $this->author_id . ']J. Doe[/Author]', $stored );
	}

	/**
	 * A byline stored before this check still reaches the editor limited to the allowlist.
	 */
	public function test_rest_returns_older_bylines_limited_to_the_post_allowlist() {
		$this->store_raw( $this->byline() );
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'editor' ] ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $this->post_id );
		$request->set_param( 'context', 'edit' );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assert_allowlisted( $response->get_data()['meta'][ Bylines::META_KEY_BYLINE ], 'rest' );
	}

	/**
	 * The byline HTML other plugins read is limited to the allowlist, with author links rendered.
	 */
	public function test_byline_html_is_limited_to_the_post_allowlist() {
		$this->store_raw( $this->byline() );
		$html = Bylines::get_custom_byline_html( $this->post_id );
		$this->assert_allowlisted( $html, 'html' );
		$this->assertStringContainsString( 'class="author vcard"', $html );
		$this->assertStringContainsString( '>Jane Doe</a>', $html );
		$this->assertStringNotContainsString( '[Author', $html );
	}
}
