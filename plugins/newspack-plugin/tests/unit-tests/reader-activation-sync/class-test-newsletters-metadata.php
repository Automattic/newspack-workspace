<?php
/**
 * Tests the Newsletters contact metadata provider.
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Data;
use Newspack\Reader_Activation\Sync\Metadata;
use Newspack\Reader_Activation\Sync\Contact_Metadata\Newsletters;

require_once __DIR__ . '/../../mocks/newsletters-mocks.php';

/**
 * The "Newsletter Selection" field, built from the reader's stored lists.
 *
 * @group Newsletters_Metadata
 */
class Test_Newsletters_Metadata extends WP_UnitTestCase {
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
	 * The provider owns the legacy field under its existing raw key and label.
	 */
	public function test_declares_the_newsletter_selection_field() {
		$this->assertSame( [ 'newsletter_selection' => 'Newsletter Selection' ], Newsletters::get_fields() );
		$this->assertTrue( Newsletters::is_available() );
	}

	/**
	 * A reader with no stored lists has an unknown selection, not an empty one.
	 */
	public function test_no_stored_lists_omits_the_field() {
		$this->assertSame(
			[],
			( new Newsletters( $this->user_id ) )->get_metadata(),
			'Without stored lists nothing is pushed, so a value the ESP already holds is left alone.'
		);
	}

	/**
	 * A stored empty list is a real state: unsubscribed from everything.
	 */
	public function test_empty_stored_list_sends_an_empty_string() {
		$this->store_lists( [] );
		$this->assertSame(
			[ Metadata::get_key( 'newsletter_selection' ) => '' ],
			( new Newsletters( $this->user_id ) )->get_metadata()
		);
	}

	/**
	 * IDs resolve to names in lists-config order; unknown IDs are ignored.
	 */
	public function test_stored_ids_map_to_list_names() {
		$this->store_lists( [ 'list-2', 'gone', 'list-1' ] );
		$this->assertSame(
			[ Metadata::get_key( 'newsletter_selection' ) => 'Daily, Weekly' ],
			( new Newsletters( $this->user_id ) )->get_metadata()
		);
	}

	/**
	 * Without a readable lists config the names cannot be resolved, so the
	 * field is omitted rather than pushed blank.
	 */
	public function test_lists_config_error_omits_the_field() {
		$this->store_lists( [ 'list-1' ] );
		Newspack_Newsletters_Subscription::$lists = new WP_Error( 'newspack_newsletters_error', 'Lists unavailable.' );
		$this->assertSame( [], ( new Newsletters( $this->user_id ) )->get_metadata() );
	}

	/**
	 * Outside legacy mode the pipeline prefixes keys itself, so the raw key is returned.
	 */
	public function test_new_schema_returns_the_raw_key() {
		Metadata::$version = '1.0';
		$this->store_lists( [ 'list-1' ] );
		$this->assertSame( [ 'newsletter_selection' => 'Daily' ], ( new Newsletters( $this->user_id ) )->get_metadata() );
	}
}
