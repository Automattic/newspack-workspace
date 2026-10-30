<?php
/**
 * Tests that the access_source GA4 parameter stays off Access Control sites.
 *
 * @package Newspack\Tests
 */

use Newspack\GoogleSiteKit;

/**
 * Lives in its own class because the feature flag is a constant: a dozen test
 * classes define NEWSPACK_CONTENT_GATES in a beforeClass hook, and PHPUnit runs
 * those hooks inside the isolated child process too. A class that defines the
 * constant anywhere therefore cannot host a feature-off test.
 *
 * @group GoogleSiteKit_Access_Source
 */
class Newspack_Test_GoogleSiteKit_Access_Source_Feature_Off extends WP_UnitTestCase {

	/**
	 * Without Access Control the parameter is never sent.
	 *
	 * Absence of the key is the flag's doing rather than an empty value: on a
	 * singular view the resolver's quietest answer is `no_custom_access_gate`,
	 * which is non-empty and would have been sent.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_parameter_is_omitted_when_access_control_is_off() {
		$this->assertFalse(
			Newspack\Content_Gate::is_newspack_feature_enabled(),
			'Process isolation failed: the flag leaked in, so nothing here was tested.'
		);

		$post_id = $this->factory->post->create();
		$this->go_to( get_permalink( $post_id ) );

		$params = GoogleSiteKit::get_custom_event_parameters();

		$this->assertArrayNotHasKey( 'access_source', $params );
		$this->assertArrayHasKey( 'is_reader', $params, 'The rest of the parameters are unaffected.' );
	}
}
