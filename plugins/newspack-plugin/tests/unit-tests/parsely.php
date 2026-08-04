<?php
/**
 * Tests the Parse.ly meta_type migration.
 *
 * @package Newspack\Tests
 */

use Newspack\Parsely;

/**
 * Tests the Parse.ly meta_type migration.
 */
class Newspack_Test_Parsely extends WP_UnitTestCase {

	/**
	 * Reset migration state and active plugins before each test.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( Parsely::META_TYPE_MIGRATION_OPTION );
		delete_option( 'parsely' );
		update_option( 'active_plugins', [] );
	}

	/**
	 * Mark wp-parsely as active.
	 */
	private function activate_parsely() {
		update_option( 'active_plugins', [ 'wp-parsely/wp-parsely.php' ] );
	}

	/**
	 * A stored `json_ld` meta_type is switched to `repeated_metas`.
	 */
	public function test_migrates_explicit_json_ld() {
		$this->activate_parsely();
		update_option(
			'parsely',
			[
				'apikey'    => 'example.com',
				'meta_type' => 'json_ld',
			]
		);

		Parsely::migrate_meta_type();

		$settings = get_option( 'parsely' );
		$this->assertEquals( 'repeated_metas', $settings['meta_type'] );
		$this->assertEquals( 'example.com', $settings['apikey'] );
		$this->assertNotEmpty( get_option( Parsely::META_TYPE_MIGRATION_OPTION ) );
	}

	/**
	 * A missing meta_type key is treated as the legacy `json_ld` default and rewritten.
	 */
	public function test_migrates_absent_meta_type() {
		$this->activate_parsely();
		update_option( 'parsely', [ 'apikey' => 'example.com' ] );

		Parsely::migrate_meta_type();

		$settings = get_option( 'parsely' );
		$this->assertEquals( 'repeated_metas', $settings['meta_type'] );
		$this->assertEquals( 'example.com', $settings['apikey'] );
	}

	/**
	 * An empty stored option array is migrated (absent key === legacy default).
	 */
	public function test_migrates_empty_option() {
		$this->activate_parsely();
		update_option( 'parsely', [] );

		Parsely::migrate_meta_type();

		$settings = get_option( 'parsely' );
		$this->assertEquals( 'repeated_metas', $settings['meta_type'] );
	}

	/**
	 * A non-`json_ld` meta_type is left untouched.
	 */
	public function test_leaves_other_meta_type_untouched() {
		$this->activate_parsely();
		update_option( 'parsely', [ 'meta_type' => 'repeated_metas' ] );

		Parsely::migrate_meta_type();

		$settings = get_option( 'parsely' );
		$this->assertEquals( 'repeated_metas', $settings['meta_type'] );
		$this->assertNotEmpty( get_option( Parsely::META_TYPE_MIGRATION_OPTION ) );
	}

	/**
	 * Nothing happens — and completion is not recorded — when wp-parsely is inactive,
	 * so the migration stays pending until the plugin is activated.
	 */
	public function test_skips_and_stays_pending_when_parsely_inactive() {
		update_option( 'parsely', [ 'meta_type' => 'json_ld' ] );

		Parsely::migrate_meta_type();

		$settings = get_option( 'parsely' );
		$this->assertEquals( 'json_ld', $settings['meta_type'] );
		$this->assertFalse( get_option( Parsely::META_TYPE_MIGRATION_OPTION ) );
	}

	/**
	 * The migration is idempotent: once recorded it does not run again, even if
	 * the stored meta_type is later reset to `json_ld`.
	 */
	public function test_is_idempotent() {
		$this->activate_parsely();
		update_option( 'parsely', [ 'meta_type' => 'json_ld' ] );

		Parsely::migrate_meta_type();
		$this->assertEquals( 'repeated_metas', get_option( 'parsely' )['meta_type'] );

		// Simulate a later manual change back to json_ld; the migration should not re-run.
		update_option( 'parsely', [ 'meta_type' => 'json_ld' ] );
		Parsely::migrate_meta_type();
		$this->assertEquals( 'json_ld', get_option( 'parsely' )['meta_type'] );
	}
}
