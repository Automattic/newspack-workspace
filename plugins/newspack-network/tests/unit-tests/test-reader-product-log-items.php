<?php
/**
 * Class TestReaderProductLogItems
 *
 * @package Newspack_Network
 */

use Newspack_Network\Hub\Node;
use Newspack_Network\Hub\Stores\Event_Log_Items\Group_Seat_Changed;
use Newspack_Network\Hub\Stores\Event_Log_Items\One_Time_Purchase_Changed;

/**
 * What the hub's Event Log screen says about a seat or purchase event.
 */
class TestReaderProductLogItems extends WP_UnitTestCase {

	/**
	 * An Event Log item of the given class.
	 *
	 * @param string $class_name Item class.
	 * @param string $action     Action name.
	 * @param array  $data       Event data.
	 * @return object
	 */
	private function item( $class_name, $action, $data ) {
		return new $class_name(
			[
				'id'          => 1,
				'node'        => new Node( 0 ),
				'action_name' => $action,
				'email'       => $data['email'],
				'data'        => wp_json_encode( $data ),
				'timestamp'   => time(),
			]
		);
	}

	/**
	 * A seat event names the member, the group subscription, and the seat's status.
	 */
	public function test_seat_summary() {
		$summary = $this->item(
			Group_Seat_Changed::class,
			'newspack_node_group_seat_changed',
			[
				'email'        => 'member@example.test',
				'id'           => 90,
				'status_after' => 'cancelled',
			]
		)->get_summary();

		$this->assertStringContainsString( 'member@example.test', $summary );
		$this->assertStringContainsString( '#90', $summary );
		$this->assertStringContainsString( 'cancelled', $summary );
	}

	/**
	 * A purchase event names the order and its status.
	 */
	public function test_purchase_summary() {
		$summary = $this->item(
			One_Time_Purchase_Changed::class,
			'newspack_node_one_time_purchase_changed',
			[
				'email'        => 'reader@example.test',
				'id'           => 86,
				'status_after' => 'refunded',
			]
		)->get_summary();

		$this->assertStringContainsString( '#86', $summary );
		$this->assertStringContainsString( 'refunded', $summary );
	}
}
