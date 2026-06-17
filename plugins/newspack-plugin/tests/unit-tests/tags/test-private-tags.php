<?php
/**
 * Tests for the Newspack\Private_Tags class — core behavior (feature flag, term meta,
 * settings + gating, the shared label helper, and cache invalidation).
 *
 * Frontend filters live in test-private-tags-frontend.php; integrations (GAM, Yoast,
 * REST, admin) live in test-private-tags-integrations.php. Split by behavior area to
 * keep each test file focused and reasonably sized.
 *
 * @package Newspack\Tests
 */

use Newspack\Private_Tags;

require_once __DIR__ . '/traits/trait-private-tags-test-helper.php';

/**
 * Core Private_Tags class test matrix.
 *
 * @group private-tags
 */
class Test_Private_Tags extends WP_UnitTestCase {

	use Private_Tags_Test_Helper;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->enable_private_tags_feature();
		$this->reset_private_tags_state();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		$this->reset_private_tags_state();
		parent::tear_down();
	}

	// -----------------------------------------------------------------
	// Feature flag.
	// -----------------------------------------------------------------

	/**
	 * Is enabled returns true when constant defined.
	 */
	public function test_is_enabled_returns_true_when_constant_defined() {
		$this->assertTrue( Private_Tags::is_enabled() );
	}

	// -----------------------------------------------------------------
	// is_term_private().
	// -----------------------------------------------------------------

	/**
	 * Is term private true for marked tag.
	 */
	public function test_is_term_private_true_for_marked_tag() {
		$id = $this->make_private_tag( 'Beastie' );
		$this->assertTrue( Private_Tags::is_term_private( get_term( $id, 'post_tag' ) ) );
	}

	/**
	 * Is term private false for unmarked tag.
	 */
	public function test_is_term_private_false_for_unmarked_tag() {
		$id = $this->make_public_tag( 'Jazz' );
		$this->assertFalse( Private_Tags::is_term_private( get_term( $id, 'post_tag' ) ) );
	}

	/**
	 * Is term private false for non post tag taxonomy.
	 */
	public function test_is_term_private_false_for_non_post_tag_taxonomy() {
		$cat_id = $this->factory()->term->create( [ 'taxonomy' => 'category' ] );
		update_term_meta( $cat_id, Private_Tags::META_KEY, 1 );
		$this->assertFalse( Private_Tags::is_term_private( get_term( $cat_id, 'category' ) ) );
	}

	// -----------------------------------------------------------------
	// Default settings + is_behavior_enabled().
	// -----------------------------------------------------------------

	/**
	 * Default settings has expected keys.
	 */
	public function test_default_settings_has_expected_keys() {
		$settings = Private_Tags::get_settings();
		foreach ( [ 'all', 'archives', 'feeds', 'feed_terms', 'tag_links', 'tag_clouds', 'css_classes', 'gam_targeting', 'yoast_metadata', 'yoast_sitemap' ] as $key ) {
			$this->assertArrayHasKey( $key, $settings, "Default settings missing key: {$key}" );
			$this->assertTrue( $settings[ $key ], "Default for {$key} should be true" );
		}
	}

	/**
	 * Is behavior enabled returns true when all master is on.
	 */
	public function test_is_behavior_enabled_returns_true_when_all_master_is_on() {
		$this->set_private_tags_settings( [ 'all' => true ] );
		// Individual flag is off, but master 'all' overrides.
		$this->assertTrue( Private_Tags::is_behavior_enabled( 'tag_links' ) );
		$this->assertTrue( Private_Tags::is_behavior_enabled( 'feed_terms' ) );
	}

	/**
	 * Is behavior enabled uses individual flag when all off.
	 */
	public function test_is_behavior_enabled_uses_individual_flag_when_all_off() {
		$this->set_private_tags_settings(
			[
				'all'       => false,
				'tag_links' => true,
			]
		);
		$this->assertTrue( Private_Tags::is_behavior_enabled( 'tag_links' ) );
		$this->assertFalse( Private_Tags::is_behavior_enabled( 'feed_terms' ) );
	}

	// -----------------------------------------------------------------
	// sanitize_settings().
	// -----------------------------------------------------------------

	/**
	 * Sanitize settings whitelists known keys.
	 */
	public function test_sanitize_settings_whitelists_known_keys() {
		$raw = [
			'all'        => '1',
			'feeds'      => true,
			'unknownkey' => true,
		];
		$out = Private_Tags::sanitize_settings( $raw );
		$this->assertArrayHasKey( 'all', $out );
		$this->assertTrue( $out['all'] );
		$this->assertArrayHasKey( 'feeds', $out );
		$this->assertTrue( $out['feeds'] );
		$this->assertArrayNotHasKey( 'unknownkey', $out );
	}

	/**
	 * Sanitize settings casts missing keys to false.
	 */
	public function test_sanitize_settings_casts_missing_keys_to_false() {
		$out = Private_Tags::sanitize_settings( [ 'all' => true ] );
		// Missing 'archives' key should be coerced to false.
		$this->assertFalse( $out['archives'] );
		$this->assertFalse( $out['feed_terms'] );
	}

	/**
	 * Sanitize settings handles non array input.
	 */
	public function test_sanitize_settings_handles_non_array_input() {
		$out = Private_Tags::sanitize_settings( 'garbage' );
		$this->assertIsArray( $out );
		$this->assertFalse( $out['all'] );
	}

	// -----------------------------------------------------------------
	// maybe_append_private_label().
	// -----------------------------------------------------------------

	/**
	 * Maybe append private label adds suffix for private tag.
	 */
	public function test_maybe_append_private_label_adds_suffix_for_private_tag() {
		$id   = $this->make_private_tag( 'Beastie' );
		$name = Private_Tags::maybe_append_private_label( $id, 'Beastie' );
		$this->assertStringEndsWith( '(private)', $name );
	}

	/**
	 * Maybe append private label unchanged for public tag.
	 */
	public function test_maybe_append_private_label_unchanged_for_public_tag() {
		$id = $this->make_public_tag( 'Jazz' );
		$this->assertSame( 'Jazz', Private_Tags::maybe_append_private_label( $id, 'Jazz' ) );
	}

	/**
	 * Maybe append private label idempotent on already suffixed name.
	 */
	public function test_maybe_append_private_label_idempotent_on_already_suffixed_name() {
		$id    = $this->make_private_tag( 'Beastie' );
		$once  = Private_Tags::maybe_append_private_label( $id, 'Beastie' );
		$twice = Private_Tags::maybe_append_private_label( $id, $once );
		$this->assertSame( $once, $twice );
	}

	/**
	 * Maybe append private label returns non string unchanged.
	 */
	public function test_maybe_append_private_label_returns_non_string_unchanged() {
		$id = $this->make_private_tag( 'Beastie' );
		$this->assertNull( Private_Tags::maybe_append_private_label( $id, null ) );
		$this->assertSame( [], Private_Tags::maybe_append_private_label( $id, [] ) );
	}

	/**
	 * Maybe append private label handles name containing private substring.
	 */
	public function test_maybe_append_private_label_handles_name_containing_private_substring() {
		// A name that contains "(private)" mid-string should still get the suffix appended.
		$id  = $this->make_private_tag( 'My (private) Notes' );
		$out = Private_Tags::maybe_append_private_label( $id, 'My (private) Notes' );
		$this->assertStringEndsWith( '(private)', $out );
		$this->assertNotSame( 'My (private) Notes', $out );
	}

	// -----------------------------------------------------------------
	// Cache invalidation.
	// -----------------------------------------------------------------

	/**
	 * Clear cache drops stored ids.
	 */
	public function test_clear_cache_drops_stored_ids() {
		$id = $this->make_private_tag( 'Beastie' );
		// Prime cache.
		Private_Tags::maybe_append_private_label( $id, 'Beastie' );
		// Remove the meta directly + clear cache; next lookup should miss.
		delete_term_meta( $id, Private_Tags::META_KEY );
		Private_Tags::clear_cache();
		$this->assertSame( 'Beastie', Private_Tags::maybe_append_private_label( $id, 'Beastie' ) );
	}

	/**
	 * Maybe clear cache only fires for meta key.
	 */
	public function test_maybe_clear_cache_only_fires_for_meta_key() {
		$id     = $this->make_private_tag( 'Beastie' );
		$public = $this->make_public_tag( 'Jazz' );
		// Prime cache.
		$this->assertStringEndsWith( '(private)', Private_Tags::maybe_append_private_label( $id, 'Beastie' ) );

		// Unrelated meta change: should NOT clear the cache. If it did, the next call
		// would re-query (still returning the same result). To detect a (wrong) cache
		// clear, we delete the private meta DIRECTLY via $wpdb so the action doesn't
		// fire, then flip an unrelated meta. If the unrelated meta change clears the
		// cache, the next lookup will re-query and miss; if not, the stale cache wins.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- intentional bypass of meta hooks for test isolation.
		$wpdb->delete(
			$wpdb->termmeta,
			[
				'term_id'  => $id,
				'meta_key' => Private_Tags::META_KEY,
			]
		);
		wp_cache_delete( $id, 'term_meta' );
		update_term_meta( $public, 'unrelated_key', 'value' );

		// Cache should still be populated with the (now stale) private ID — label persists.
		$this->assertStringEndsWith(
			'(private)',
			Private_Tags::maybe_append_private_label( $id, 'Beastie' ),
			'Unrelated meta updates must not invalidate the private-tag cache.'
		);
	}

	/**
	 * Cache invalidated when private meta changes.
	 */
	public function test_cache_invalidated_when_private_meta_changes() {
		$id = $this->make_public_tag( 'Jazz' );
		// Prime cache as non-private.
		$this->assertSame( 'Jazz', Private_Tags::maybe_append_private_label( $id, 'Jazz' ) );
		// Mark private — this should trigger added_term_meta → maybe_clear_cache.
		update_term_meta( $id, Private_Tags::META_KEY, 1 );
		$this->assertStringEndsWith( '(private)', Private_Tags::maybe_append_private_label( $id, 'Jazz' ) );
	}
}
