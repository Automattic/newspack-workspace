<?php
/**
 * Test helper trait for Private_Tags tests.
 *
 * @package Newspack\Tests
 */

use Newspack\Private_Tags;

/**
 * Shared setup/teardown for Private_Tags test classes.
 *
 * Re-registers Private_Tags hooks before each test and provides per-test reset of
 * the static caches (which survive WP's per-test DB rollback).
 */
trait Private_Tags_Test_Helper {

	/**
	 * Re-register Private_Tags hooks for the current test.
	 *
	 * The feature is on by default, so no constant is needed to enable it. The
	 * class registers init() on `after_setup_theme` (so it runs once during the
	 * test bootstrap), but WP_UnitTestCase::set_up()
	 * snapshots $wp_filter and tear_down() restores it — hooks registered after
	 * the snapshot are removed between tests. Flip $initiated back to false and
	 * re-run init() each test to force a fresh hook registration. (add_filter is
	 * idempotent for the same callback + priority, so re-running cannot double-register.)
	 */
	protected function enable_private_tags_feature() {
		$ref  = new ReflectionClass( Private_Tags::class );
		$prop = $ref->getProperty( 'initiated' );
		$prop->setAccessible( true );
		$prop->setValue( null, false );
		Private_Tags::init();
	}

	/**
	 * Reset all in-class static state.
	 *
	 * Clears the public ID/slug/class caches and, via reflection, the cached settings
	 * snapshot. Required between tests because static properties aren't rolled back
	 * by WP's transaction-based test isolation.
	 */
	protected function reset_private_tags_state() {
		Private_Tags::clear_cache();
		$ref  = new ReflectionClass( Private_Tags::class );
		$prop = $ref->getProperty( 'settings' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
		delete_option( 'newspack_private_tags_settings' );
	}

	/**
	 * Directly write a settings snapshot and reset the in-class cache.
	 *
	 * Bypasses the wizard save path so tests can pinpoint a single behavior.
	 *
	 * @param array<string, bool> $settings Settings to persist (merged into defaults).
	 */
	protected function set_private_tags_settings( array $settings ) {
		// sanitize_settings() whitelists the canonical setting keys and fills any
		// missing ones with false, so we don't duplicate the key list here (which
		// could drift as new behaviors are added).
		update_option( 'newspack_private_tags_settings', Private_Tags::sanitize_settings( $settings ) );
		$ref  = new ReflectionClass( Private_Tags::class );
		$prop = $ref->getProperty( 'settings' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	/**
	 * Create a tag and mark it private.
	 *
	 * @param string $name Tag name.
	 * @return int Term ID.
	 */
	protected function make_private_tag( $name ) {
		$id = $this->factory()->term->create(
			[
				'taxonomy' => 'post_tag',
				'name'     => $name,
			]
		);
		update_term_meta( $id, Private_Tags::META_KEY, 1 );
		Private_Tags::clear_cache();
		return $id;
	}

	/**
	 * Create a normal (public) tag.
	 *
	 * @param string $name Tag name.
	 * @return int Term ID.
	 */
	protected function make_public_tag( $name ) {
		return $this->factory()->term->create(
			[
				'taxonomy' => 'post_tag',
				'name'     => $name,
			]
		);
	}
}
