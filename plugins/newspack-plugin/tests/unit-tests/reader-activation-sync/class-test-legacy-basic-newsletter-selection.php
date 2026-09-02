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
}
