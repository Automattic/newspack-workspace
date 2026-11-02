<?php
/**
 * Class TestAbstractBackfiller
 *
 * @package Newspack_Network
 */

namespace Newspack_Network\Tests;

use Newspack\Data_Events\Webhooks;
use Newspack_Network\Backfillers\Abstract_Backfiller;
use Newspack_Network\Incoming_Events\Reader_Registered;
use Newspack_Network\Site_Role;

require_once dirname( __DIR__ ) . '/mocks/class-webhooks.php';

/**
 * How a node's backfill treats events it has already sent.
 */
class TestAbstractBackfiller extends \WP_UnitTestCase {

	/**
	 * Run as a node.
	 */
	public function set_up() {
		parent::set_up();
		update_option( Site_Role::OPTION_NAME, Site_Role::NODE_ROLE );
		Webhooks::$dispatched = [];
	}

	/**
	 * Clean up.
	 */
	public function tear_down() {
		delete_option( Site_Role::OPTION_NAME );
		parent::tear_down();
	}

	/**
	 * A reader-registered event, optionally already sent.
	 *
	 * @param string $email        Reader email.
	 * @param bool   $already_sent Whether a webhook request for it exists.
	 * @return Reader_Registered
	 */
	private function event( $email, $already_sent ) {
		$data = [ 'email' => $email ];
		if ( $already_sent ) {
			$request_id = self::factory()->post->create( [ 'post_type' => Webhooks::REQUEST_POST_TYPE ] );
			update_post_meta( $request_id, 'timestamp', 1000 );
			update_post_meta( $request_id, 'action_name', 'reader_registered' );
			update_post_meta( $request_id, 'data', wp_json_encode( $data ) );
		}
		return new Reader_Registered( get_bloginfo( 'url' ), $data, 1000 );
	}

	/**
	 * An event already sent is skipped, and the events after it are still sent.
	 */
	public function test_backfill_skips_sent_events_and_continues() {
		$events     = [
			$this->event( 'first@example.test', true ),
			$this->event( 'second@example.test', false ),
			$this->event( 'third@example.test', true ),
			$this->event( 'fourth@example.test', false ),
		];
		$backfiller = new class( $events ) extends Abstract_Backfiller {
			/**
			 * Constructor: a live, verbose run over the given events.
			 *
			 * @param array $events Events to process.
			 */
			public function __construct( private $events ) {
				parent::__construct( null, null, true, true );
			}

			/**
			 * Events to process.
			 *
			 * @return array
			 */
			public function get_events() {
				return $this->events;
			}

			/**
			 * Verbose output line.
			 *
			 * @param object $event Event.
			 * @return string
			 */
			protected function get_processed_item_output( $event ) {
				return $event->get_email();
			}
		};

		$backfiller->process_events();

		$this->assertSame( [ 'second@example.test', 'fourth@example.test' ], wp_list_pluck( Webhooks::$dispatched, 'email' ) );
	}
}
