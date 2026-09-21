<?php
/**
 * Test Bylines::republication_tracker_tool_byline_prefix().
 *
 * @package Newspack\Tests
 */

namespace Newspack\Tests;

use WP_UnitTestCase;
use Newspack\Bylines;

/**
 * Test class for the Republication Tracker Tool byline-prefix compatibility filter.
 */
class Test_Republication_Tracker_Tool_Byline_Prefix extends WP_UnitTestCase {

	/**
	 * Test post ID.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		Bylines::register_post_meta();

		$this->post_id = $this->factory->post->create();
	}

	/**
	 * When a Custom Byline is active, Republication Tracker Tool's own
	 * prefix should be suppressed, since the Custom Byline already includes
	 * its own leading text (e.g. "By ...").
	 */
	public function test_suppresses_prefix_when_custom_byline_active() {
		$GLOBALS['post'] = get_post( $this->post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		update_post_meta( $this->post_id, Bylines::META_KEY_ACTIVE, true );
		update_post_meta( $this->post_id, Bylines::META_KEY_BYLINE, 'By [Author id=999]Jane Doe[/Author]' );

		$this->assertSame( '', Bylines::republication_tracker_tool_byline_prefix( 'by ' ) );
	}

	/**
	 * When there's no active Custom Byline, the incoming prefix passes
	 * through unchanged (e.g. for CAP or the WP post author).
	 */
	public function test_passes_through_prefix_when_no_custom_byline() {
		$GLOBALS['post'] = get_post( $this->post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->assertSame( 'by ', Bylines::republication_tracker_tool_byline_prefix( 'by ' ) );
	}

	/**
	 * An inactive Custom Byline (toggled off, meta retained) should not
	 * suppress the prefix either.
	 */
	public function test_passes_through_prefix_when_custom_byline_inactive() {
		$GLOBALS['post'] = get_post( $this->post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		update_post_meta( $this->post_id, Bylines::META_KEY_ACTIVE, false );
		update_post_meta( $this->post_id, Bylines::META_KEY_BYLINE, 'By [Author id=999]Jane Doe[/Author]' );

		$this->assertSame( 'by ', Bylines::republication_tracker_tool_byline_prefix( 'by ' ) );
	}
}
