<?php
/**
 * Tests that the hub Membership Plans list table escapes node-supplied output.
 *
 * @package Newspack_Network_Hub
 */

use Newspack_Network\Hub\Admin\Membership_Plans_Table;

/**
 * Verify membership-plan columns escape node-supplied values.
 */
class Test_Membership_Plans_Table_Escaping extends WP_UnitTestCase {

	/**
	 * Base item with a payload in every node-supplied field.
	 *
	 * @param string $payload The XSS marker.
	 * @return array
	 */
	private function item( $payload ) {
		return [
			'id'                         => 9,
			'name'                       => $payload,
			'site_url'                   => $payload,
			'network_pass_id'            => $payload,
			'active_memberships_count'   => 4,
			'network_pass_discrepancies' => [],
		];
	}

	/**
	 * The name, network_pass_id and default (site_url) columns must escape.
	 */
	public function test_columns_are_escaped() {
		$payload = '<img src=x onerror=NPPM3042>';
		$table   = new Membership_Plans_Table();
		$item    = $this->item( $payload );

		foreach ( [ 'name', 'network_pass_id', 'site_url' ] as $column ) {
			$out = $table->column_default( $item, $column );
			$this->assertStringNotContainsString( '<img src=x', $out, "Column {$column} rendered a live tag" );
			$this->assertStringContainsString( '&lt;img src=x onerror=NPPM3042&gt;', $out, "Column {$column} not escaped" );
		}
	}

	/**
	 * A non-scalar value in the default case renders empty, not "Array" + notice.
	 *
	 * The column that actually reaches the guard is network_pass_discrepancies:
	 * its own branch is gated on a truthy network_pass_id, so a falsy one drops
	 * an array into the catch-all. Asserting on a column get_columns() never
	 * produces would document a hypothetical instead.
	 */
	public function test_default_case_guards_non_scalar() {
		$table = new Membership_Plans_Table();
		$item  = $this->item( 'x' );

		$item['network_pass_id']            = '';
		$item['network_pass_discrepancies'] = [ 'reader@example.com' ];

		$out = $table->column_default( $item, 'network_pass_discrepancies' );
		$this->assertSame( '', $out, 'An array reaching the catch-all must render empty.' );
	}

	/**
	 * The plan links escape their href, not only their text.
	 *
	 * The tag-shaped payload above is neutralised by the esc_html() on the link
	 * text, so it would still pass with the href escaping removed. This payload
	 * carries no `<` at all and can only be stopped at the attribute.
	 */
	public function test_plan_link_href_is_escaped() {
		$table = new Membership_Plans_Table();
		$item  = $this->item( 'https://node.test" onmouseover=NPPM3042 x="' );

		$out = $table->column_default( $item, 'name' );

		$this->assertSame( 2, substr_count( $out, '"' ), 'An attribute was broken out of: ' . $out );

		preg_match( '/href="([^"]*)"/', $out, $matches );
		$href = isset( $matches[1] ) ? $matches[1] : '';
		$this->assertNotSame( '', $href, 'Precondition: the plan name links somewhere.' );
		$this->assertStringContainsString( '%20', $href, 'esc_url() did not encode the injected whitespace.' );
	}
}
