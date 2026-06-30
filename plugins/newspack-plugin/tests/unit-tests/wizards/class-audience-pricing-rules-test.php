<?php
/**
 * Tests for the Audience Pricing Rules wizard shell.
 *
 * @package Newspack\Tests
 */

use Newspack\Audience_Pricing_Rules;

class Audience_Pricing_Rules_Test extends WP_UnitTestCase {
	public function test_slug_and_name(): void {
		$wizard = new Audience_Pricing_Rules();
		$this->assertSame( 'newspack-audience-pricing-rules', $wizard->get_slug() );
		$this->assertNotEmpty( $wizard->get_name() );
	}
}
