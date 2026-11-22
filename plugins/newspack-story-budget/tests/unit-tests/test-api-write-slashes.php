<?php
/**
 * Tests that the app's write routes store backslashes as sent.
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget;

require_once dirname( __DIR__ ) . '/trait-rest-story-helpers.php';

/**
 * Values sent as JSON, as the app sends them, are stored character for character.
 */
class Test_API_Write_Slashes extends \WP_UnitTestCase {
	use REST_Story_Helpers;

	const TITLE       = 'Files in C:\\drafts\\final';
	const DESCRIPTION = 'Archive at D:\\exports\\2026';

	/**
	 * Setup.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->budget_id = self::factory()->term->create( [ 'taxonomy' => Budgets::TAXONOMY ] );

		// Guard against a literal that lost its backslashes, which would let a test pass without testing anything.
		$this->assertStringContainsString( '\\', self::TITLE );
		$this->assertStringContainsString( '\\', self::DESCRIPTION );
	}

	/**
	 * Empty the field registry, so a field added by a filter gets registered on the next read.
	 */
	private static function reset_field_registry() {
		( new \ReflectionProperty( Fields::class, 'all_fields' ) )->setValue( null, [] );
		( new \ReflectionProperty( Fields::class, 'is_registered' ) )->setValue( null, false );
	}

	/**
	 * Teardown.
	 */
	public function tear_down() {
		remove_all_filters( 'newspack_story_budget_fields' );
		self::reset_field_registry();
		parent::tear_down();
	}

	/**
	 * Creating a story keeps backslashes in its title and fields.
	 */
	public function test_create_story_keeps_backslashes() {
		$response = $this->rest(
			'POST',
			'/stories',
			[
				'title'       => self::TITLE,
				'name'        => self::TITLE,
				'description' => self::DESCRIPTION,
				'budgets'     => [ $this->budget_id ],
			]
		);
		$this->assertSame( 200, $response->get_status() );
		$data    = $response->get_data();
		$post_id = (int) $data['id'];

		$this->assertSame( self::TITLE, get_post_field( 'post_title', $post_id, 'raw' ) );
		$this->assertSame( self::TITLE, get_post_meta( $post_id, Fields::get_field( 'name' )->get_post_meta_name(), true ) );
		$this->assertSame( self::DESCRIPTION, get_post_meta( $post_id, $this->description_key(), true ) );
		$this->assertSame( self::TITLE, $data['title'] );
		$this->assertSame( self::TITLE, $data['name'] );
		$this->assertSame( self::DESCRIPTION, $data['description'] );
	}

	/**
	 * Saving a story, as the story screen and the editor panel do, keeps backslashes.
	 */
	public function test_save_story_keeps_backslashes() {
		$post_id  = $this->create_story_via_app( 'Quarterly archive' );
		$response = $this->rest( 'POST', '/stories/' . $post_id, [ 'description' => self::DESCRIPTION ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::DESCRIPTION, get_post_meta( $post_id, $this->description_key(), true ) );
		$this->assertSame( self::DESCRIPTION, $response->get_data()['description'] );
	}

	/**
	 * Saving one field, as the inline field editor does, keeps backslashes.
	 */
	public function test_save_story_field_keeps_backslashes() {
		$post_id  = $this->create_story_via_app( 'Quarterly archive' );
		$response = $this->rest( 'POST', '/stories/' . $post_id . '/description', [ 'value' => self::DESCRIPTION ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::DESCRIPTION, get_post_meta( $post_id, $this->description_key(), true ) );
		$this->assertSame( self::DESCRIPTION, $response->get_data()['description'] );
	}

	/**
	 * Bulk editing stories keeps backslashes.
	 */
	public function test_bulk_update_keeps_backslashes() {
		$first    = $this->create_story_via_app( 'Quarterly archive' );
		$second   = $this->create_story_via_app( 'Monthly archive' );
		$response = $this->rest(
			'POST',
			'/stories/update',
			[
				'ids'    => [ $first, $second ],
				'fields' => [
					[
						'slug'  => 'description',
						'value' => self::DESCRIPTION,
					],
				],
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::DESCRIPTION, get_post_meta( $first, $this->description_key(), true ) );
		$this->assertSame( self::DESCRIPTION, get_post_meta( $second, $this->description_key(), true ) );
		$this->assertSame(
			[ self::DESCRIPTION, self::DESCRIPTION ],
			wp_list_pluck( $response->get_data()['stories'], 'description' )
		);
	}

	/**
	 * A user whose titles go through kses still keeps backslashes in the title.
	 */
	public function test_contributor_create_keeps_backslashes() {
		$contributor = self::factory()->user->create( [ 'role' => 'contributor' ] );
		wp_set_current_user( $contributor );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$this->assertNotFalse( has_filter( 'title_save_pre', 'wp_filter_kses' ), 'kses filters the title for this user.' );

		$post_id = $this->create_story_via_app( self::TITLE );

		$this->assertSame( self::TITLE, get_post_field( 'post_title', $post_id, 'raw' ) );
	}

	/**
	 * A field with multiple values adds and removes values containing backslashes.
	 */
	public function test_multiple_field_add_and_remove_with_backslashes() {
		$alias = 'a\\b';
		$this->assertStringContainsString( '\\', $alias );

		add_filter(
			'newspack_story_budget_fields',
			function ( $fields ) {
				$fields[] = [
					'description' => 'Other names for the story.',
					'is_editable' => true,
					'is_multiple' => true,
					'name'        => 'Aliases',
					'slug'        => 'aliases',
					'type'        => 'text',
				];
				return $fields;
			}
		);
		self::reset_field_registry();
		$this->assertNotNull( Fields::get_field( 'aliases' ) );
		$meta_key = Fields::get_field( 'aliases' )->get_post_meta_name();

		$post_id  = $this->create_story_via_app( 'Quarterly archive' );
		$response = $this->rest( 'POST', '/stories/' . $post_id, [ 'aliases' => [ $alias, 'c' ] ] );
		$this->assertSame( 200, $response->get_status() );
		$stored = get_post_meta( $post_id, $meta_key, false );
		sort( $stored );
		$this->assertSame( [ $alias, 'c' ], $stored );

		$response = $this->rest( 'POST', '/stories/' . $post_id, [ 'aliases' => [ 'c' ] ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'c' ], get_post_meta( $post_id, $meta_key, false ) );
	}

	/**
	 * Values with quotes and no backslash are stored as before on every write route.
	 */
	public function test_quotes_without_backslashes_unchanged() {
		$value = 'O\'Brien\'s "notes"';

		$created = $this->create_story_via_app( 'Quarterly archive', [ 'description' => $value ] );
		$this->assertSame( $value, get_post_meta( $created, $this->description_key(), true ) );

		$saved = $this->create_story_via_app( 'Monthly archive' );
		$this->rest( 'POST', '/stories/' . $saved, [ 'description' => $value ] );
		$this->assertSame( $value, get_post_meta( $saved, $this->description_key(), true ) );

		$field = $this->create_story_via_app( 'Weekly archive' );
		$this->rest( 'POST', '/stories/' . $field . '/description', [ 'value' => $value ] );
		$this->assertSame( $value, get_post_meta( $field, $this->description_key(), true ) );

		$bulk = $this->create_story_via_app( 'Daily archive' );
		$this->rest(
			'POST',
			'/stories/update',
			[
				'ids'    => [ $bulk ],
				'fields' => [
					[
						'slug'  => 'description',
						'value' => $value,
					],
				],
			]
		);
		$this->assertSame( $value, get_post_meta( $bulk, $this->description_key(), true ) );
	}
}
