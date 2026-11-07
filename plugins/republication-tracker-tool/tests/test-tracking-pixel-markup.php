<?php
/**
 * Class TrackingPixelMarkupTest
 *
 * @package Republication_Tracker_Tool
 */

/**
 * The pixel markup the Republish Copy button looks for.
 */
class TrackingPixelMarkupTest extends WP_UnitTestCase {
	/**
	 * The Copy button finds the handout's tracking code by this exact opening
	 * (`trackingAnchor` in assets/clipboard-utils.js) and wraps it so the block
	 * editor keeps the scripts. If the pixel markup stops starting this way, the
	 * button falls back to a plain-text copy without any error, and block-editor
	 * pastes drop the tracking scripts. Change the two together.
	 */
	public function test_tracking_pixel_markup_starts_with_the_copy_button_anchor() {
		$post_id = self::factory()->post->create();

		$this->assertStringStartsWith(
			'<img id="republication-tracker-tool-source"',
			Republication_Tracker_Tool::create_tracking_pixel_markup( $post_id )
		);
	}
}
