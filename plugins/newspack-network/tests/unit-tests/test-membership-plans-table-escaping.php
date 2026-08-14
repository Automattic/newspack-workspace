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
	 */
	public function test_default_case_guards_non_scalar() {
		$table = new Membership_Plans_Table();
		$item  = $this->item( 'x' );
		$item['some_future_column'] = [ 'a', 'b' ];

		$out = $table->column_default( $item, 'some_future_column' );
		$this->assertSame( '', $out );
	}
}
