<?php
/**
 * Tests the base/util Newspack functionality.
 *
 * @package Newspack\Tests
 */

use Newspack\Newspack;

/**
 * Test base/util functionality.
 */
class Newspack_Test_Newspack extends WP_UnitTestCase {

	/**
	 * Test that Newspack has been successfully loaded into the test suite.
	 */
	public function test_newspack_loaded() {
		$this->assertTrue( defined( 'NEWSPACK_VERSION' ) );
	}

	/**
	 * Test that the Newspack class is set up correctly.
	 */
	public function test_newspack_class() {
		$newspack = Newspack::instance();

		$this->assertInstanceOf( Newspack::class, $newspack );
		$this->assertSame( $newspack, Newspack::instance() );
	}

	/**
	 * The default cascade returns the display name when it is a normal label.
	 */
	public function test_get_user_display_label_default_cascade() {
		$user_id = $this->factory->user->create(
			[
				'display_name' => 'Jane Doe',
				'user_login'   => 'jane-doe',
			]
		);
		$this->assertSame( 'Jane Doe', \Newspack\newspack_get_user_display_label( $user_id ) );
	}

	/**
	 * A real name wins over an email-valued display_name earlier in the cascade.
	 */
	public function test_get_user_display_label_real_name_beats_email_display_name() {
		$user_id = $this->factory->user->create(
			[
				'display_name' => 'reader@example.com',
				'first_name'   => 'Reader',
				'last_name'    => 'Person',
				'user_login'   => 'reader-person',
			]
		);
		$label = \Newspack\newspack_get_user_display_label( $user_id, [ 'display_name', 'full_name', 'login' ] );
		$this->assertSame( 'Reader Person', $label );
		$this->assertFalse( (bool) is_email( $label ) );
	}

	/**
	 * With no real label available, an email candidate is used as a last resort,
	 * reduced to its local part rather than leaking the full address.
	 */
	public function test_get_user_display_label_falls_back_to_email_local_part() {
		$user_id = $this->factory->user->create(
			[
				'display_name' => 'reader@example.com',
				'user_login'   => 'reader@example.com',
			]
		);
		$this->assertSame( 'reader', \Newspack\newspack_get_user_display_label( $user_id, [ 'display_name', 'login' ] ) );
	}

	/**
	 * An explicit 'email' strategy still returns the address.
	 */
	public function test_get_user_display_label_explicit_email_strategy() {
		$user_id = $this->factory->user->create(
			[
				'user_email' => 'reader@example.com',
			]
		);
		$this->assertSame( 'reader@example.com', \Newspack\newspack_get_user_display_label( $user_id, [ 'email' ] ) );
	}
}
