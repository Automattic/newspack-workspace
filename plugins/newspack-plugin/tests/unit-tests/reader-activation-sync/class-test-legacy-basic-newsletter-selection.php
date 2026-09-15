<?php
/**
 * Tests the legacy "Newsletter Selection" field built by Legacy_Basic.
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Data;
use Newspack\Reader_Activation\Sync\Metadata;
use Newspack\Reader_Activation\Sync\Contact_Metadata\Legacy_Basic;

require_once __DIR__ . '/../../mocks/newsletters-mocks.php';

/**
 * The legacy "Newsletter Selection" field, built from the reader's stored lists.
 *
 * @group Legacy_Basic_Newsletter_Selection
 */
class Test_Legacy_Basic_Newsletter_Selection extends WP_UnitTestCase {
	/**
	 * Metadata version before the test class ran.
	 *
	 * @var string
	 */
	private static $original_version;

	/**
	 * Reader under test.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Class-level setup.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
		self::$original_version = Metadata::$version;
	}

	/**
	 * Per-test setup.
	 */
	public function set_up() {
		parent::set_up();
		Metadata::$version = 'legacy';
		$this->reset_reported_omissions();
		Newspack_Newsletters_Subscription::reset_calls();
		Newspack_Newsletters_Subscription::$lists = [
			[
				'active' => true,
				'name'   => 'Daily',
				'id'     => 'list-1',
			],
			[
				'active' => true,
				'name'   => 'Weekly',
				'id'     => 'list-2',
			],
		];
		$this->user_id = $this->factory->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => 'reader@example.com',
			]
		);
	}

	/**
	 * Per-test teardown.
	 */
	public function tear_down() {
		Metadata::$version = self::$original_version;
		Newspack_Newsletters_Subscription::reset_calls();
		parent::tear_down();
	}

	/**
	 * Store list IDs the way the reader-data event handlers do.
	 *
	 * @param string[] $ids List IDs.
	 */
	private function store_lists( array $ids ) {
		Reader_Data::update_item( $this->user_id, 'newsletter_subscribed_lists', wp_json_encode( $ids ) );
	}

	/**
	 * Build the legacy basic metadata for the reader.
	 *
	 * @return array
	 */
	private function get_metadata() {
		return ( new Legacy_Basic( $this->user_id ) )->get_metadata();
	}

	/**
	 * The prefixed key the field is pushed under.
	 *
	 * @return string
	 */
	private function key() {
		return Metadata::get_key( 'newsletter_selection' );
	}

	/**
	 * Forget which omission reasons were already sent to the remote log, so
	 * each test starts as a fresh request would.
	 */
	private function reset_reported_omissions() {
		$property = new ReflectionProperty( Legacy_Basic::class, 'reported_omissions' );
		$property->setAccessible( true );
		$property->setValue( null, [] );
	}

	/**
	 * Build the metadata while capturing every newspack_log entry it fires.
	 *
	 * @return array[] Captured entries as [ code, message, params ].
	 */
	private function get_metadata_capturing_logs() {
		$logged  = [];
		$capture = function ( $code, $message, $params ) use ( &$logged ) {
			$logged[] = compact( 'code', 'message', 'params' );
		};
		add_action( 'newspack_log', $capture, 10, 3 );
		$this->get_metadata();
		remove_action( 'newspack_log', $capture, 10 );
		return $logged;
	}

	/**
	 * A reader with no stored lists has an unknown selection, not an empty one.
	 */
	public function test_no_stored_lists_omits_the_field() {
		$metadata = $this->get_metadata();
		$this->assertArrayHasKey( Metadata::get_key( 'account' ), $metadata, 'The other legacy fields are still built.' );
		$this->assertArrayNotHasKey(
			$this->key(),
			$metadata,
			'Without stored lists nothing is pushed, so a value the ESP already holds is left alone.'
		);
	}

	/**
	 * A stored empty list is a real state: unsubscribed from everything.
	 */
	public function test_empty_stored_list_sends_an_empty_string() {
		$this->store_lists( [] );
		$this->assertSame( '', $this->get_metadata()[ $this->key() ] ?? 'missing' );
	}

	/**
	 * A stored empty list needs no name resolution, so it is sent even when the
	 * lists config is unreadable, and without consulting it.
	 */
	public function test_empty_stored_list_needs_no_lists_config() {
		$this->store_lists( [] );
		Newspack_Newsletters_Subscription::$lists = new WP_Error( 'newspack_newsletters_error', 'Lists unavailable.' );
		Newspack_Newsletters_Subscription::$get_lists_calls = 0;
		$this->assertSame( '', $this->get_metadata()[ $this->key() ] ?? 'missing' );
		$this->assertSame( 0, Newspack_Newsletters_Subscription::$get_lists_calls );
	}

	/**
	 * IDs resolve to names in lists-config order; unknown IDs are ignored.
	 */
	public function test_stored_ids_map_to_list_names() {
		$this->store_lists( [ 'list-2', 'gone', 'list-1' ] );
		$this->assertSame( 'Daily, Weekly', $this->get_metadata()[ $this->key() ] ?? 'missing' );
	}

	/**
	 * Without a readable lists config the names cannot be resolved, so the
	 * field is omitted rather than pushed blank.
	 */
	public function test_lists_config_error_omits_the_field() {
		$this->store_lists( [ 'list-1' ] );
		Newspack_Newsletters_Subscription::$lists = new WP_Error( 'newspack_newsletters_error', 'Lists unavailable.' );
		$this->assertArrayNotHasKey( $this->key(), $this->get_metadata() );
	}

	/**
	 * Stored IDs that match no configured list are a resolution failure (a
	 * deleted list, IDs from a previous provider), not a reader on no lists,
	 * so the field is omitted rather than pushed blank over the ESP value.
	 */
	public function test_all_unknown_stored_ids_omit_the_field() {
		$this->store_lists( [ 'gone', 'also-gone' ] );
		$metadata = $this->get_metadata();
		$this->assertArrayHasKey( Metadata::get_key( 'account' ), $metadata, 'The other legacy fields are still built.' );
		$this->assertArrayNotHasKey( $this->key(), $metadata );
	}

	/**
	 * An omission is recorded through the newspack_log action, which reaches
	 * production logs, since nothing else explains a field that did not arrive.
	 * The entry names the reader and carries a stable reason.
	 */
	public function test_omission_is_recorded_through_newspack_log() {
		$this->store_lists( [ 'gone' ] );
		$logged = $this->get_metadata_capturing_logs();
		$this->assertCount( 1, $logged );
		$this->assertSame( 'newspack_esp_sync_newsletter_selection', $logged[0]['code'] );
		$this->assertStringContainsString( sprintf( 'user %d', $this->user_id ), $logged[0]['message'] );
		$this->assertStringContainsString( 'match no configured list', $logged[0]['message'] );
		$this->assertSame( 'reader@example.com', $logged[0]['params']['user_email'] );
		$this->assertSame( 'stored_lists_unknown', $logged[0]['params']['data']['reason'] );
		$this->assertSame( 2, $logged[0]['params']['log_level'], 'The first omission per reason is sent to the remote log.' );
	}

	/**
	 * A backfill builds every reader in one request, so the same reason is
	 * sent to the remote log once per request; later omissions stay local.
	 */
	public function test_repeated_omissions_in_one_request_stay_local() {
		$this->store_lists( [ 'gone' ] );
		$this->get_metadata_capturing_logs();
		$logged = $this->get_metadata_capturing_logs();
		$this->assertCount( 1, $logged, 'Every omission is still recorded.' );
		$this->assertSame( 1, $logged[0]['params']['log_level'] );
	}

	/**
	 * An empty selection is not an omission: the stored empty list is the
	 * record, so nothing is sent to the durable log.
	 */
	public function test_empty_selection_is_not_recorded_as_an_omission() {
		$this->store_lists( [] );
		$this->assertSame( [], $this->get_metadata_capturing_logs() );
	}

	/**
	 * The field follows the ESP's outgoing-field selection like every other
	 * legacy field, because it is added before normalization.
	 */
	public function test_unselected_outgoing_field_is_not_sent() {
		$this->store_lists( [ 'list-1' ] );
		Metadata::update_fields( array_values( array_diff( Metadata::get_default_fields(), [ 'Newsletter Selection' ] ) ) );
		$metadata = $this->get_metadata();
		$this->assertArrayHasKey( Metadata::get_key( 'account' ), $metadata );
		$this->assertArrayNotHasKey( $this->key(), $metadata );
	}
	/**
	 * A value an earlier removal left in object shape may have dropped later
	 * changes, so the selection is unknown and the field is omitted.
	 */
	public function test_object_shaped_storage_omits_the_field() {
		update_user_meta( $this->user_id, 'newspack_reader_data_keys', [ 'newsletter_subscribed_lists' ] );
		update_user_meta( $this->user_id, Reader_Data::get_meta_key_name( 'newsletter_subscribed_lists' ), '{"1":"list-2"}' );
		$metadata = $this->get_metadata();
		$this->assertArrayHasKey( Metadata::get_key( 'account' ), $metadata, 'The other legacy fields are still built.' );
		$this->assertArrayNotHasKey( $this->key(), $metadata );
	}

	/**
	 * When the field is not an enabled outgoing field the lists config is not
	 * consulted at all, so a disabled field costs nothing per contact.
	 */
	public function test_unselected_outgoing_field_skips_the_lists_lookup() {
		$this->store_lists( [ 'list-1' ] );
		Metadata::update_fields( array_values( array_diff( Metadata::get_default_fields(), [ 'Newsletter Selection' ] ) ) );
		Newspack_Newsletters_Subscription::$get_lists_calls = 0;
		$this->get_metadata();
		$this->assertSame( 0, Newspack_Newsletters_Subscription::$get_lists_calls );
	}
}
