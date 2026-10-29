<?php
/**
 * Tests for newspack-ui snackbar notices.
 *
 * @package Newspack\Tests
 */

use Newspack\Newspack_UI;

/**
 * Test how snackbar notices print.
 *
 * @group Newspack_UI_Notices
 */
class Newspack_Test_UI_Notices extends WP_UnitTestCase {

	/**
	 * Empty the notice queue.
	 */
	public function tear_down(): void {
		$queue = new ReflectionProperty( Newspack_UI::class, 'notices' );
		$queue->setValue( null, [] );
		parent::tear_down();
	}

	/**
	 * Print the queued notices.
	 *
	 * @return string The printed markup.
	 */
	private function print_notices(): string {
		ob_start();
		Newspack_UI::print_notices();
		return ob_get_clean();
	}

	/**
	 * An error stays until the reader closes it, even when a caller asks for it to hide.
	 */
	public function test_errors_stay_until_closed(): void {
		Newspack_UI::add_notice(
			'We couldn\'t update your newsletter preferences.',
			[
				'type'     => 'error',
				'autohide' => true,
			]
		);
		Newspack_UI::add_notice( 'Something went wrong.', 'error' );

		$html = $this->print_notices();

		$this->assertSame( 2, substr_count( $html, 'data-autohide="false"' ) );
		$this->assertSame( 2, substr_count( $html, 'newspack-ui__snackbar__close' ) );
	}
}
