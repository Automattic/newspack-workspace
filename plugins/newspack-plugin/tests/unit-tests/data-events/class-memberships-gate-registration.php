<?php
/**
 * Tests the Memberships data-events listener for registrations through a gate.
 *
 * @package Newspack\Tests
 */

use Newspack\Data_Events\Memberships;

/**
 * A registration that carries a gate id produces a gate_interaction event.
 * The producers of that metadata disagree on which key names the page the
 * reader was on: the Reader Registration block and the auth modal set
 * `referer`, the Newsletter Subscription Form block sets `current_page_url`.
 * The listener has to read either without a notice.
 *
 * @group data-events
 */
class Newspack_Test_Data_Events_Memberships_Gate_Registration extends WP_UnitTestCase {

	/**
	 * Metadata from the newsletter block: gate id present, no `referer`.
	 */
	public function test_newsletter_block_registration_uses_current_page_url_as_referer() {
		$data = Memberships::registration_submission(
			'reader@example.test',
			true,
			123,
			false,
			[
				'gate_post_id'        => 456,
				'registration_method' => 'newsletters-subscription',
				'current_page_url'    => 'https://example.test/gated-post/',
			]
		);

		$this->assertSame( 'https://example.test/gated-post/', $data['referer'] );
		$this->assertSame( 'registration', $data['action_type'] );
	}

	/**
	 * Metadata from the registration block: `referer` wins when both are present.
	 */
	public function test_registration_block_referer_is_kept() {
		$data = Memberships::registration_submission(
			'reader@example.test',
			true,
			123,
			false,
			[
				'gate_post_id'        => 456,
				'registration_method' => 'registration-block',
				'referer'             => 'https://example.test/from-referer/',
				'current_page_url'    => 'https://example.test/from-page-url/',
			]
		);

		$this->assertSame( 'https://example.test/from-referer/', $data['referer'] );
	}

	/**
	 * No gate id, no event: the listener is only for registrations through a gate.
	 */
	public function test_registration_without_a_gate_produces_no_event() {
		$this->assertNull(
			Memberships::registration_submission( 'reader@example.test', true, 123, false, [ 'referer' => 'https://example.test/' ] )
		);
	}
}
