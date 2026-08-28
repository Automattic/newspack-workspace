<?php
/**
 * Tests for the Integrations onboarding dismissal.
 *
 * @package Newspack\Tests
 */

use Newspack\Audience_Integrations;

/**
 * Onboarding dismissal behavior.
 *
 * @group audience_integrations
 */
class Test_Audience_Integrations_Onboarding extends WP_UnitTestCase {
	/**
	 * Dismissing stores a per-user flag and reports success, so each admin
	 * sees the introduction exactly once.
	 */
	public function test_dismissal_is_stored_per_user() {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$wizard   = new Audience_Integrations();
		$response = $wizard->api_dismiss_onboarding();

		$this->assertSame( [ 'dismissed' => true ], $response->get_data() );
		$this->assertTrue( (bool) get_user_meta( $user_id, Audience_Integrations::ONBOARDING_DISMISSED_META, true ) );

		$other_user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->assertEmpty( get_user_meta( $other_user, Audience_Integrations::ONBOARDING_DISMISSED_META, true ), 'Dismissal is per user.' );
	}
}
