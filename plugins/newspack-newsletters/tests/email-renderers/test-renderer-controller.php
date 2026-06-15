<?php
/**
 * Class Renderer Controller Test
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Email_Renderers\Renderer_Controller;

/**
 * Renderer Controller Test.
 */
class Test_Renderer_Controller extends WP_UnitTestCase {
	/**
	 * Unstamped posts resolve to the legacy MJML engine.
	 */
	public function test_unstamped_post_resolves_to_mjml() {
		$post_id = self::factory()->post->create();
		$this->assertSame( 'mjml', Renderer_Controller::get_post_renderer( $post_id ) );
	}

	/**
	 * A stamped post resolves to its stored engine value.
	 */
	public function test_stamped_post_resolves_to_stored_value() {
		$post_id = self::factory()->post->create();
		Renderer_Controller::stamp_renderer( $post_id, 'wc' );
		$this->assertSame( 'wc', Renderer_Controller::get_post_renderer( $post_id ) );
	}

	/**
	 * An unknown engine value is normalized to the legacy MJML engine.
	 */
	public function test_unknown_engine_normalizes_to_mjml() {
		$post_id = self::factory()->post->create();
		Renderer_Controller::stamp_renderer( $post_id, 'something-else' );
		$this->assertSame( 'mjml', Renderer_Controller::get_post_renderer( $post_id ) );
	}
}
