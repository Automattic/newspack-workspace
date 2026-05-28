<?php
/**
 * Tests ad suppression block asset enqueuing.
 *
 * @package Newspack\Tests
 */

use Newspack_Ads\Suppression;

/**
 * Test ad suppression block asset enqueuing.
 *
 * Regression coverage for https://github.com/Automattic/newspack-ads/pull/1072:
 * Suppression::enqueue_block_assets() is hooked to `enqueue_block_assets`, which
 * fires on the front end (including the customizer preview iframe) as well as in
 * admin. In those non-admin-screen contexts `get_current_screen()` is either
 * undefined or returns null, so the unguarded `get_current_screen()->post_type`
 * call caused a fatal error and broke the Customize screen.
 *
 * Note on coverage: the live incident was specifically `Call to undefined
 * function get_current_screen()` in the customizer preview, where wp-admin is
 * not loaded. That exact branch (the `! function_exists( 'get_current_screen' )`
 * guard) is not reproducible in the PHPUnit harness: the WordPress test
 * bootstrap always loads `wp-admin/includes/screen.php`, so the function is
 * permanently defined for the process, PHP cannot un-define it, process
 * isolation re-runs the same bootstrap, and the `\get_current_screen()` call is
 * explicitly global-namespaced so a namespaced shadow is never consulted. Both
 * guards protect the same vulnerable statement (`->post_type` on a value that
 * is not a WP_Screen); the test below exercises that statement via the
 * reproducible `null` path, pinning the function-level contract the incident
 * violated. The undefined-function path can only be covered by an end-to-end
 * test that actually renders the customizer preview.
 */
class SuppressionTest extends WP_UnitTestCase {

	/**
	 * The current screen prior to the test, restored on tear down.
	 *
	 * @var WP_Screen|null
	 */
	private $previous_screen;

	/**
	 * Set up: force the block editor asset gate to pass so the screen check is
	 * actually reached, and remember the current screen for restoration.
	 */
	public function set_up() {
		parent::set_up();
		$this->previous_screen = isset( $GLOBALS['current_screen'] ) ? $GLOBALS['current_screen'] : null;
		add_filter( 'should_load_block_editor_scripts_and_styles', '__return_true' );
	}

	/**
	 * Tear down: clean up filters, both enqueued/registered assets (the handler
	 * enqueues a script *and* a style under the same handle), and the screen
	 * state. Done here so isolation holds even when an assertion fails and
	 * aborts the test method before any in-test cleanup runs.
	 */
	public function tear_down() {
		remove_filter( 'should_load_block_editor_scripts_and_styles', '__return_true' );
		wp_dequeue_script( 'newspack-ads-suppress-ads' );
		wp_deregister_script( 'newspack-ads-suppress-ads' );
		wp_dequeue_style( 'newspack-ads-suppress-ads' );
		wp_deregister_style( 'newspack-ads-suppress-ads' );
		$GLOBALS['current_screen'] = $this->previous_screen;
		parent::tear_down();
	}

	/**
	 * Regression: enqueue_block_assets() must bail cleanly instead of erroring
	 * on get_current_screen()->post_type when there is no block-editor screen.
	 *
	 * Models the customizer preview context that triggered the incident: a
	 * front-end request (not wp-admin) with no current screen set.
	 */
	public function test_enqueue_block_assets_without_current_screen() {
		// Front-end query context, as in the customizer preview iframe.
		$this->go_to( home_url( '/' ) );
		// No admin screen is set in that context.
		$GLOBALS['current_screen'] = null;

		Suppression::enqueue_block_assets();

		self::assertFalse(
			wp_script_is( 'newspack-ads-suppress-ads', 'enqueued' ),
			'Suppression script should not be enqueued when there is no block-editor screen.'
		);
		self::assertFalse(
			wp_style_is( 'newspack-ads-suppress-ads', 'enqueued' ),
			'Suppression style should not be enqueued when there is no block-editor screen.'
		);
	}

	/**
	 * The happy path still works: on a viewable post-type block-editor screen
	 * the suppression assets are enqueued, so the guard cannot regress into
	 * never enqueuing.
	 */
	public function test_enqueue_block_assets_on_post_editor_screen() {
		set_current_screen( 'post' );

		Suppression::enqueue_block_assets();

		self::assertTrue(
			wp_script_is( 'newspack-ads-suppress-ads', 'enqueued' ),
			'Suppression script should be enqueued on a viewable post-type editor screen.'
		);
	}
}
