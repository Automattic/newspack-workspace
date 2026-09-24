<?php
/**
 * Tests for the Tracking\Utils merge-tag helpers.
 *
 * @package Newspack_Newsletters
 */

use Newspack_Newsletters\Tracking\Utils;

/**
 * Tracking Utils Test.
 */
class Newsletters_Tracking_Utils_Test extends WP_UnitTestCase {
	/**
	 * Wraps a field in the active ESP's merge-tag delimiters.
	 */
	public function test_get_merge_tag_uses_active_provider_syntax() {
		\Newspack_Newsletters::set_service_provider( 'mailchimp' );
		$this->assertSame( '*|HUB-MEMBER|*', Utils::get_merge_tag( 'HUB-MEMBER' ) );

		\Newspack_Newsletters::set_service_provider( 'active_campaign' );
		$this->assertSame( '%HUB-MEMBER%', Utils::get_merge_tag( 'HUB-MEMBER' ) );

		\Newspack_Newsletters::set_service_provider( 'constant_contact' );
		$this->assertSame( '[[HUB-MEMBER]]', Utils::get_merge_tag( 'HUB-MEMBER' ) );
	}

	/**
	 * An empty field name yields no tag, so callers can append unconditionally
	 * and skip on an empty return.
	 */
	public function test_get_merge_tag_returns_empty_for_empty_field() {
		\Newspack_Newsletters::set_service_provider( 'mailchimp' );
		$this->assertSame( '', Utils::get_merge_tag( '' ) );
		$this->assertSame( '', Utils::get_merge_tag( '   ' ) );
	}
}
