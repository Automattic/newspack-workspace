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
		$gate_id = $this->create_gate(
			[
				'enabled' => false,
				'count'   => 0,
				'period'  => 'month',
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
		$gate_id = $this->create_gate(
			[
				'enabled' => true,
				'count'   => 3,
				'period'  => 'month',
			]
		);
		$this->assertTrue(
			Metering::offers_metering( $gate_id, true ),
			'The fixture gate must actually offer metering, or the no-write guarantee is untested.'
		);

		$before = get_user_meta( $user_id );
		Metering::offers_metering( $gate_id, true );
		$after = get_user_meta( $user_id );

		$this->assertSame( $before, $after, 'Reading metering availability must not write user meta.' );
	}

	/**
	 * Publish a gate carrying the given custom-access metering settings.
	 *
	 * Configured through the `custom_access` meta rather than
	 * Metering::update_metering_settings(), which writes the legacy `metering`
	 * meta that offers_metering() only reads while Woo Memberships is active —
	 * so a gate configured that way meters nobody here, and both tests would
	 * assert against a gate that was never metered in the first place.
	 *
	 * @param array $metering Metering settings: `enabled`, `count`, `period`.
	 *
	 * @return int Gate post ID.
	 */
	private function create_gate( $metering ) {
		$gate_id = $this->factory->post->create(
			[
				'post_type'   => Newspack\Content_Gate::GATE_CPT,
				'post_status' => 'publish',
			]
		);
		update_post_meta(
			$gate_id,
			'custom_access',
			[
				'active'       => true,
				'access_rules' => [
					[
						[
							'slug'  => 'email_domain',
							'value' => 'nobody.example',
						],
					],
				],
				'metering'     => $metering,
			]
		);
		return $gate_id;
	}
}
