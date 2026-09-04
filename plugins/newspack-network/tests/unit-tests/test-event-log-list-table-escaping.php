<?php
/**
 * Tests that the hub Event Log list table escapes node-supplied column output.
 *
 * @package Newspack_Network_Hub
 */

use Newspack_Network\Hub\Admin\Event_Log_List_Table;

/**
 * Verify every dynamic Event Log column is escaped at output.
 */
class Test_Event_Log_List_Table_Escaping extends WP_UnitTestCase {

	/**
	 * Build a stub event-log item exposing the getters column_default reads.
	 *
	 * @param string $summary     Summary text.
	 * @param string $node_url    Node URL.
	 * @param string $action_name Action name.
	 * @param mixed  $data        Decoded data payload.
	 * @return object
	 */
	private function make_item( $summary, $node_url, $action_name, $data ) {
		return new class( $summary, $node_url, $action_name, $data ) {
			/**
			 * Summary text.
			 *
			 * @var string
			 */
			public $summary;
			/**
			 * Node URL.
			 *
			 * @var string
			 */
			public $node_url;
			/**
			 * Action name.
			 *
			 * @var string
			 */
			public $action_name;
			/**
			 * Data payload.
			 *
			 * @var mixed
			 */
			public $data;
			/**
			 * Store the stub's data.
			 *
			 * @param string $summary     Summary.
			 * @param string $node_url    Node URL.
			 * @param string $action_name Action name.
			 * @param mixed  $data        Data.
			 */
			public function __construct( $summary, $node_url, $action_name, $data ) {
				$this->summary     = $summary;
				$this->node_url    = $node_url;
				$this->action_name = $action_name;
				$this->data        = $data;
			}
			/**
			 * Get the summary.
			 *
			 * @return string
			 */
			public function get_summary() {
				return $this->summary; }
			/**
			 * Get the node URL.
			 *
			 * @return string
			 */
			public function get_node_url() {
				return $this->node_url; }
			/**
			 * Get the action name.
			 *
			 * @return string
			 */
			public function get_action_name() {
				return $this->action_name; }
			/**
			 * Get the data payload.
			 *
			 * @return mixed
			 */
			public function get_data() {
				return $this->data; }
		};
	}

	/**
	 * The summary, node and action_name columns must be HTML-escaped.
	 */
	public function test_text_columns_are_escaped() {
		$payload = '<img src=x onerror=NPPM3042>';
		$table   = new Event_Log_List_Table();
		$item    = $this->make_item( $payload, $payload, $payload, [ 'note' => $payload ] );

		foreach ( [ 'summary', 'node', 'action_name' ] as $column ) {
			$out = $table->column_default( $item, $column );
			$this->assertStringNotContainsString( '<img src=x', $out, "Column {$column} rendered a live tag" );
			$this->assertStringContainsString( '&lt;img src=x onerror=NPPM3042&gt;', $out, "Column {$column} not escaped" );
		}
	}

	/**
	 * The data column JSON must be HTML-escaped, including quotes/slashes and a
	 * </textarea> breakout in the large-payload branch.
	 */
	public function test_data_column_is_escaped() {
		$table = new Event_Log_List_Table();

		// Small payload -> <pre><code> branch.
		$small = $this->make_item( 's', 'n', 'a', [ 'note' => '<img src=x onerror=NPPM3042>' ] );
		$out   = $table->column_default( $small, 'data' );
		$this->assertStringNotContainsString( '<img src=x', $out );
		$this->assertStringContainsString( '&lt;img src=x onerror=NPPM3042&gt;', $out );

		// Large payload (>300 chars) -> <textarea> branch, with a breakout attempt.
		$big_note = str_repeat( 'A', 320 ) . '</textarea><img src=x onerror=NPPM3042>';
		$big      = $this->make_item( 's', 'n', 'a', [ 'note' => $big_note ] );
		$out      = $table->column_default( $big, 'data' );
		$this->assertStringNotContainsString( '</textarea><img', $out );
		$this->assertStringNotContainsString( '<img src=x', $out );
		$this->assertStringContainsString( '&lt;img src=x onerror=NPPM3042&gt;', $out );
	}

	/**
	 * The inline/collapsed threshold measures the raw JSON, not the escaped
	 * string.
	 *
	 * Escaping turns each quote into six bytes, so a payload comfortably under
	 * the limit can cross it on punctuation alone and render collapsed when it
	 * should render inline. The two preconditions below are the point of the
	 * test: they assert the fixture actually straddles the boundary, so the
	 * assertion cannot quietly stop meaning anything if the limit moves.
	 */
	public function test_threshold_measures_unescaped_json() {
		$data = [
			'action'   => 'subscription_changed',
			'status'   => 'active',
			'node'     => 'example',
			'user'     => 'reader',
			'plan'     => 'monthly',
			'ref'      => 'abc123',
			'currency' => 'usd',
			'gateway'  => 'stripe',
		];
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT );

		$this->assertLessThan( 300, strlen( $json ), 'Precondition: the raw JSON is under the limit.' );
		$this->assertGreaterThan( 300, strlen( esc_html( $json ) ), 'Precondition: escaping pushes it over.' );

		$out = ( new Event_Log_List_Table() )->column_default( $this->make_item( 's', 'n', 'a', $data ), 'data' );

		$this->assertStringContainsString( '<pre><code>', $out, 'Payload under the limit rendered collapsed.' );
		$this->assertStringNotContainsString( '<textarea', $out, 'Payload under the limit rendered collapsed.' );
	}

	/**
	 * The collapsed branch must survive a clipboard round-trip.
	 *
	 * The clipboard script in js/event-log.js reads the textarea's .value and
	 * copies it verbatim. The browser decodes entities on the way out, so the
	 * escaper has to encode the ampersand of an existing entity, or the copied
	 * text differs from the data.
	 */
	public function test_textarea_round_trips_entities() {
		$note = str_repeat( 'A', 320 ) . ' &amp; more';
		$out  = ( new Event_Log_List_Table() )->column_default(
			$this->make_item( 's', 'n', 'a', [ 'note' => $note ] ),
			'data'
		);

		$this->assertStringContainsString( '<textarea', $out, 'Precondition: the payload took the collapsed branch.' );
		$this->assertStringContainsString( '&amp;amp;', $out, 'The ampersand was not encoded, so the clipboard copy alters the data.' );
	}
}
