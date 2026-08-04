<?php
/**
 * Tests for the Audience Pricing Rules wizard shell.
 *
 * @package Newspack\Tests
 */

use Newspack\Audience_Pricing_Rules;

/**
 * Tests for the Audience Pricing Rules wizard shell.
 */
class Audience_Pricing_Rules_Test extends WP_UnitTestCase {
	/**
	 * The wizard exposes its slug and a non-empty name.
	 */
	public function test_slug_and_name(): void {
		$wizard = new Audience_Pricing_Rules();
		$this->assertSame( 'newspack-audience-pricing-rules', $wizard->get_slug() );
		$this->assertNotEmpty( $wizard->get_name() );
	}
}
