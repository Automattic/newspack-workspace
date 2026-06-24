<?php
/**
 * Test shortcode.
 *
 * @package super-cool-ad-inserter
 */

/**
 * Test shortcode functions.
 */
class ScaipShortcodeTestFunctions extends WP_UnitTestCase {
	/**
	 * Test the scaip_shortcode function.
	 */
	public function test_scaip_shortcode() {
		$scaip_index = 1;
		$text        = 'Ad ' . $scaip_index;
		add_action(
			'scaip_shortcode',
			function ( $args ) {
				echo 'Ad ' . esc_attr( $args['number'] );
			}
		);
		$ret = scaip_shortcode( array( 'number' => $scaip_index ), '', '' );
		$this->assertMatchesRegularExpression( "/{$text}/", $ret );
	}
}
