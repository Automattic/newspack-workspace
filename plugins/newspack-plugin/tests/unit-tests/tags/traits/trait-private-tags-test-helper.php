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
 * Defines the feature-flag constant, primes init() once, and provides
 * per-test reset of the static caches (which survive WP's per-test DB rollback).
 */
trait Private_Tags_Test_Helper {

	/**
	 * Define the feature flag constant (idempotent) and run Private_Tags::init() once.
	 *
	 * The class auto-runs init() at file load time with the constant undefined,
	 * so $initiated stays false and our explicit call here is what wires hooks.
	 */
	protected function enable_private_tags_feature() {
		// NOTE: the feature-flag-OFF path (is_enabled() false) is intentionally not
		// covered. Once NEWSPACK_PRIVATE_TAGS_ENABLED is defined it can't be undefined
		// within the process, and the failure mode is benign (flag off → the feature
		// no-ops, which is the correct result). Covering it would require a dedicated
		// @runInSeparateProcess test; the cost outweighs the value for a rollout gate.
		if ( ! defined( 'NEWSPACK_PRIVATE_TAGS_ENABLED' ) ) {
			define( 'NEWSPACK_PRIVATE_TAGS_ENABLED', true );
		}
		// WP_UnitTestCase::set_up() snapshots $wp_filter and tear_down() restores it —
		// hooks registered after the snapshot are removed between tests. Force a fresh
		// re-registration each test by flipping $initiated back to false.
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
