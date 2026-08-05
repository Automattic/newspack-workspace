<?php
/**
 * Tests the read-only metering availability check.
 *
 * @package Newspack\Tests
 */

use Newspack\Metering;

/**
 * The analytics layer needs to know whether a gate meters without consuming a
 * metered view, which the allowance check does as a side effect.
 *
 * @group Metering_Offers
 */
class Newspack_Test_Metering_Offers extends WP_UnitTestCase {

	/**
	 * A gate with metering switched off offers nothing.
	 */
	public function test_unmetered_gate_offers_no_metering() {
		$gate_id = $this->factory->post->create( [ 'post_type' => Newspack\Content_Gate::GATE_CPT ] );
		Metering::update_metering_settings(
			$gate_id,
			[
				'enabled'          => false,
				'anonymous_count'  => 0,
				'registered_count' => 0,
				'period'           => 'month',
			]
		);

		$this->assertFalse( Metering::offers_metering( $gate_id, false ) );
		$this->assertFalse( Metering::offers_metering( $gate_id, true ) );
	}

	/**
	 * Asking the question must not record a metered view against the reader.
	 */
	public function test_asking_does_not_write_metering_user_meta() {
		$user_id = $this->factory->user->create();
		wp_set_current_user( $user_id );
		$gate_id = $this->factory->post->create( [ 'post_type' => Newspack\Content_Gate::GATE_CPT ] );
		Metering::update_metering_settings(
			$gate_id,
			[
				'enabled'          => true,
				'anonymous_count'  => 3,
				'registered_count' => 3,
				'period'           => 'month',
			]
		);

		$before = get_user_meta( $user_id );
		Metering::offers_metering( $gate_id, true );
		$after = get_user_meta( $user_id );

		$this->assertSame( $before, $after, 'Reading metering availability must not write user meta.' );
	}
}
