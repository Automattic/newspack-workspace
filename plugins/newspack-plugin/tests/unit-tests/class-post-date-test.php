<?php
/**
 * Tests for Post Date features.
 *
 * @package Newspack\Tests
 */

namespace Newspack\Tests;

use Newspack\Post_Date;

/**
 * Test class for Post_Date.
 *
 * @group post-date
 */
class Test_Post_Date extends \WP_UnitTestCase {

	/**
	 * Setup before class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		require_once NEWSPACK_ABSPATH . 'includes/class-post-date.php';
	}

	/**
	 * Original global $post value, saved in set_up and restored in tear_down.
	 *
	 * @var \WP_Post|null
	 */
	private $original_post;

	/**
	 * Setup.
	 */
	public function set_up(): void {
		parent::set_up();
		global $post;
		$this->original_post = $post;
		// Reset theme mods for each test.
		remove_theme_mod( 'post_time_ago' );
		remove_theme_mod( 'post_time_ago_cut_off' );
		remove_theme_mod( 'post_updated_date' );
		remove_theme_mod( 'post_updated_date_threshold' );
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		global $post;
		$post = $this->original_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		remove_theme_mod( 'post_time_ago' );
		remove_theme_mod( 'post_time_ago_cut_off' );
		remove_theme_mod( 'post_updated_date' );
		remove_theme_mod( 'post_updated_date_threshold' );
		parent::tear_down();
	}

	/**
	 * Helper: create a post and set its modified date via direct DB update.
	 *
	 * WordPress ignores `post_modified` in wp_insert_post, so we must update it
	 * directly in the database after creation.
	 *
	 * @param string $post_date     Post date in 'Y-m-d H:i:s' format.
	 * @param string $post_modified Post modified date in 'Y-m-d H:i:s' format.
	 * @return int Post ID.
	 */
	private function create_post_with_modified_date( $post_date, $post_modified ) {
		global $wpdb;
		$post_id = static::factory()->post->create(
			[
				'post_date'     => $post_date,
				'post_date_gmt' => $post_date,
			]
		);
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->posts,
			[
				'post_modified'     => $post_modified,
				'post_modified_gmt' => $post_modified,
			],
			[ 'ID' => $post_id ]
		);
		clean_post_cache( $post_id );
		return $post_id;
	}

	// ─── Time Ago: convert_to_time_ago() ───

	/**
	 * Test time-ago conversion for a recent post (within cutoff).
	 */
	public function test_time_ago_within_cutoff() {
		$two_hours_ago = gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS );
		$result = Post_Date::convert_to_time_ago( $two_hours_ago, 14 );
		$this->assertStringContainsString( 'ago', $result, 'Post 2 hours old should show relative date.' );
	}

	/**
	 * Test time-ago returns null for a post beyond the cutoff.
	 */
	public function test_time_ago_beyond_cutoff() {
		$twenty_days_ago = gmdate( 'Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS );
		$result = Post_Date::convert_to_time_ago( $twenty_days_ago, 14 );
		$this->assertNull( $result, 'Post beyond cutoff should return null.' );
	}

	/**
	 * Test time-ago at exact cutoff boundary returns null (not within cutoff).
	 */
	public function test_time_ago_at_boundary() {
		$exactly_14_days = gmdate( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS );
		$result = Post_Date::convert_to_time_ago( $exactly_14_days, 14 );
		$this->assertNull( $result, 'Post at exact cutoff boundary should return null.' );
	}

	/**
	 * Test time-ago with custom cutoff.
	 */
	public function test_time_ago_custom_cutoff() {
		$three_days_ago = gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS );
		$result_within = Post_Date::convert_to_time_ago( $three_days_ago, 7 );
		$this->assertStringContainsString( 'ago', $result_within, 'Post 3 days old with 7-day cutoff should show relative date.' );

		$result_beyond = Post_Date::convert_to_time_ago( $three_days_ago, 2 );
		$this->assertNull( $result_beyond, 'Post 3 days old with 2-day cutoff should return null.' );
	}

	/**
	 * Test cutoff is reduced to 1 day when updated date is enabled.
	 */
	public function test_time_ago_cutoff_reduced_when_updated_date_enabled() {
		set_theme_mod( 'post_time_ago_cut_off', 14 );

		$this->assertEquals( 14, Post_Date::get_time_ago_cutoff_days(), 'Cutoff should be 14 days by default.' );

		set_theme_mod( 'post_updated_date', true );

		$this->assertEquals( 1, Post_Date::get_time_ago_cutoff_days(), 'Cutoff should be 1 day when updated date is enabled.' );
	}

	// ─── Time Ago: get_the_date filter (classic theme) ───

	/**
	 * Test get_the_date filter converts date when feature is enabled.
	 */
	public function test_get_the_date_filter_enabled() {
		set_theme_mod( 'post_time_ago', true );
		set_theme_mod( 'post_time_ago_cut_off', 14 );

		$post_id = static::factory()->post->create(
			[
				'post_date' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ),
			]
		);

		// Simulate being in the loop so the filter applies.
		$original_in_the_loop = $GLOBALS['wp_query']->in_the_loop ?? null;

		try {
			$GLOBALS['wp_query']->in_the_loop = true;

			$date = get_the_date( '', $post_id );
			$this->assertStringContainsString( 'ago', $date, 'get_the_date should return relative date when feature is on.' );
		} finally {
			$GLOBALS['wp_query']->in_the_loop = $original_in_the_loop;
		}
	}

	/**
	 * Test get_the_date filter skips conversion outside the loop (e.g. archive titles).
	 */
	public function test_get_the_date_filter_skips_outside_loop() {
		set_theme_mod( 'post_time_ago', true );
		set_theme_mod( 'post_time_ago_cut_off', 14 );

		$post_id = static::factory()->post->create(
			[
				'post_date' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ),
			]
		);

		$date = get_the_date( '', $post_id );
		$this->assertStringNotContainsString( 'ago', $date, 'get_the_date should not convert to time-ago outside the loop.' );
	}

	/**
	 * Test get_the_date filter preserves date when feature is disabled.
	 */
	public function test_get_the_date_filter_disabled() {
		set_theme_mod( 'post_time_ago', false );

		$post_id = static::factory()->post->create(
			[
				'post_date' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ),
			]
		);

		$date = get_the_date( '', $post_id );
		$this->assertStringNotContainsString( 'ago', $date, 'get_the_date should return full date when feature is off.' );
	}

	/**
	 * Test get_the_date filter only converts default format, skips explicit formats.
	 */
	public function test_get_the_date_skips_explicit_format() {
		set_theme_mod( 'post_time_ago', true );
		set_theme_mod( 'post_time_ago_cut_off', 14 );

		$post_id = static::factory()->post->create(
			[
				'post_date' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ),
			]
		);

		$date = get_the_date( 'F j, Y', $post_id );
		$this->assertStringNotContainsString( 'ago', $date, 'Explicit format should not be converted to time-ago.' );

		$unix = get_the_date( 'U', $post_id );
		$this->assertTrue( is_numeric( $unix ), 'Unix timestamp format should return a numeric value.' );
	}

	// ─── Time Ago: render_block filter (block theme) ───

	/**
	 * Test render_block filter converts publish date block.
	 */
	public function test_render_block_time_ago_publish_date() {
		set_theme_mod( 'post_time_ago', true );
		set_theme_mod( 'post_time_ago_cut_off', 14 );

		$two_hours_ago = gmdate( 'Y-m-d\TH:i:sP', time() - 2 * HOUR_IN_SECONDS );
		$block_content = '<div class="wp-block-post-date"><time datetime="' . $two_hours_ago . '">March 11, 2026</time></div>';
		$block = [ 'blockName' => 'core/post-date' ];

		$result = Post_Date::filter_post_date_block( $block_content, $block );
		$this->assertStringContainsString( 'ago', $result, 'Publish date block should show relative date.' );
		$this->assertStringContainsString( 'datetime="' . $two_hours_ago . '"', $result, 'datetime attribute should be preserved.' );
	}

	/**
	 * Test render_block filter applies time-ago to modified date blocks
	 * and wraps with "Updated" label.
	 */
	public function test_render_block_time_ago_applies_to_modified_date() {
		set_theme_mod( 'post_time_ago', true );
		set_theme_mod( 'post_time_ago_cut_off', 14 );
		set_theme_mod( 'post_updated_date', true );
		set_theme_mod( 'post_updated_date_threshold', 24 );

		$two_hours_ago = gmdate( 'Y-m-d\TH:i:sP', time() - 2 * HOUR_IN_SECONDS );
		$block_content = '<div class="wp-block-post-date wp-block-post-date__modified-date"><time datetime="' . $two_hours_ago . '">March 11, 2026</time></div>';
		$block         = [ 'blockName' => 'core/post-date' ];

		// Create a post with a modified date well beyond threshold.
		$post_id = $this->create_post_with_modified_date(
			gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ),
			gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS )
		);

		global $post;
		$post = get_post( $post_id );

		$result = Post_Date::filter_post_date_block( $block_content, $block );
		$this->assertStringContainsString( 'Updated', $result, 'Modified date block should have Updated label.' );
		$this->assertStringContainsString( 'ago', $result, 'Modified date block should get time-ago treatment when enabled.' );
	}

	/**
	 * Test render_block filter preserves date beyond cutoff.
	 */
	public function test_render_block_time_ago_beyond_cutoff() {
		set_theme_mod( 'post_time_ago', true );
		set_theme_mod( 'post_time_ago_cut_off', 14 );

		$twenty_days_ago = gmdate( 'Y-m-d\TH:i:sP', time() - 20 * DAY_IN_SECONDS );
		$block_content = '<div class="wp-block-post-date"><time datetime="' . $twenty_days_ago . '">February 19, 2026</time></div>';
		$block = [ 'blockName' => 'core/post-date' ];

		$result = Post_Date::filter_post_date_block( $block_content, $block );
		$this->assertStringNotContainsString( 'ago', $result, 'Date beyond cutoff should not be converted.' );
		$this->assertStringContainsString( 'February 19, 2026', $result, 'Original date text should be preserved.' );
	}

	/**
	 * Test render_block preserves anchor tag when isLink is enabled.
	 */
	public function test_render_block_time_ago_preserves_link() {
		set_theme_mod( 'post_time_ago', true );
		set_theme_mod( 'post_time_ago_cut_off', 14 );

		$two_hours_ago = gmdate( 'Y-m-d\TH:i:sP', time() - 2 * HOUR_IN_SECONDS );
		$block_content = '<div class="wp-block-post-date"><time datetime="' . $two_hours_ago . '"><a href="/2026/03/11/test/">March 11, 2026</a></time></div>';
		$block         = [ 'blockName' => 'core/post-date' ];

		$result = Post_Date::filter_post_date_block( $block_content, $block );
		$this->assertStringContainsString( 'ago', $result, 'Linked date should still show time-ago.' );
		$this->assertStringContainsString( '<a href="/2026/03/11/test/">', $result, 'Anchor tag should be preserved.' );
		$this->assertStringContainsString( '</a>', $result, 'Anchor closing tag should be preserved.' );
	}

	/**
	 * Test render_block preserves anchor tag on modified date with Updated label.
	 */
	public function test_render_block_updated_label_preserves_link() {
		set_theme_mod( 'post_time_ago', false );
		set_theme_mod( 'post_updated_date', true );
		set_theme_mod( 'post_updated_date_threshold', 24 );

		$post_id = $this->create_post_with_modified_date(
			gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ),
			gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS )
		);

		global $post;
		$post = get_post( $post_id );

		$block_content = '<div class="wp-block-post-date wp-block-post-date__modified-date"><time datetime="2026-03-15T10:00:00+00:00"><a href="/2026/03/15/test/">March 15, 2026</a></time></div>';
		$block         = [ 'blockName' => 'core/post-date' ];

		$result = Post_Date::filter_post_date_block( $block_content, $block );
		$this->assertStringContainsString( 'Updated', $result, 'Modified linked date should have Updated label.' );
		$this->assertStringContainsString( '<a href="/2026/03/15/test/">', $result, 'Anchor tag should be preserved on modified date.' );
	}

	// ─── Modified Date: should_display_updated_date() ───

	/**
	 * Test modified date shows when sitewide on, beyond threshold.
	 */
	public function test_modified_date_sitewide_on_beyond_threshold() {
		set_theme_mod( 'post_updated_date', true );
		set_theme_mod( 'post_updated_date_threshold', 24 );

		$post_id = $this->create_post_with_modified_date(
			gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ),
			gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS )
		);

		$this->assertTrue(
			Post_Date::should_display_updated_date( $post_id ),
			'Modified date should display when sitewide is on and post was modified beyond threshold.'
		);
	}

	/**
	 * Test modified date hidden when sitewide on but within threshold.
	 */
	public function test_modified_date_sitewide_on_within_threshold() {
		set_theme_mod( 'post_updated_date', true );
		set_theme_mod( 'post_updated_date_threshold', 24 );

		$post_id = $this->create_post_with_modified_date(
			gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ),
			gmdate( 'Y-m-d H:i:s', time() - 1 * HOUR_IN_SECONDS )
		);

		$this->assertFalse(
			Post_Date::should_display_updated_date( $post_id ),
			'Modified date should not display when modification is within threshold hours of publish.'
		);
	}

	/**
	 * Test modified date hidden when sitewide off and no per-post override.
	 */
	public function test_modified_date_sitewide_off_no_override() {
		set_theme_mod( 'post_updated_date', false );

		$post_id = $this->create_post_with_modified_date(
			gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ),
			gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS )
		);

		$this->assertFalse(
			Post_Date::should_display_updated_date( $post_id ),
			'Modified date should not display when sitewide is off and no per-post override.'
		);
	}

	/**
	 * Test per-post show override bypasses threshold.
	 */
	public function test_modified_date_per_post_show_override() {
		set_theme_mod( 'post_updated_date', false );
		set_theme_mod( 'post_updated_date_threshold', 24 );

		// Modified only 1 hour after publish (within threshold).
		$post_id = $this->create_post_with_modified_date(
			gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ),
			gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS + HOUR_IN_SECONDS )
		);
		update_post_meta( $post_id, 'newspack_show_updated_date', true );

		$this->assertTrue(
			Post_Date::should_display_updated_date( $post_id ),
			'Per-post show override should bypass threshold.'
		);
	}

	/**
	 * Test per-post hide override when sitewide on.
	 */
	public function test_modified_date_per_post_hide_override() {
		set_theme_mod( 'post_updated_date', true );
		set_theme_mod( 'post_updated_date_threshold', 24 );

		$post_id = $this->create_post_with_modified_date(
			gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ),
			gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS )
		);
		update_post_meta( $post_id, 'newspack_hide_updated_date', true );

		$this->assertFalse(
			Post_Date::should_display_updated_date( $post_id ),
			'Modified date should not display when per-post hide override is on.'
		);
	}

	/**
	 * Test threshold of zero shows modified date immediately.
	 */
	public function test_modified_date_threshold_zero() {
		set_theme_mod( 'post_updated_date', true );
		set_theme_mod( 'post_updated_date_threshold', 0 );

		$post_id = $this->create_post_with_modified_date(
			gmdate( 'Y-m-d H:i:s', time() - 1 * HOUR_IN_SECONDS ),
			gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS )
		);

		$this->assertTrue(
			Post_Date::should_display_updated_date( $post_id ),
			'Modified date should display immediately when threshold is zero.'
		);
	}

	// ─── Modified Date: render_block filter ───

	/**
	 * Test render_block hides modified date block when sitewide off.
	 */
	public function test_render_block_hides_modified_date_when_off() {
		set_theme_mod( 'post_updated_date', false );

		$block_content = '<div class="wp-block-post-date wp-block-post-date__modified-date"><time datetime="2026-03-10T10:00:00+00:00">March 10, 2026</time></div>';
		$block = [ 'blockName' => 'core/post-date' ];

		$post_id = $this->create_post_with_modified_date(
			gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ),
			gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS )
		);

		global $post;
		$post = get_post( $post_id );

		$result = Post_Date::filter_post_date_block( $block_content, $block );
		$this->assertEmpty( $result, 'Modified date block should be hidden when sitewide is off.' );
	}

	/**
	 * Test render_block detects modified date via block bindings attributes.
	 */
	public function test_render_block_detects_modified_via_bindings() {
		set_theme_mod( 'post_updated_date', false );

		$post_id = $this->create_post_with_modified_date(
			gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ),
			gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS )
		);

		global $post;
		$post = get_post( $post_id );

		// Block with bindings attrs (no CSS class in content) should still be detected as modified.
		$block_content = '<div class="wp-block-post-date"><time datetime="2026-03-16T10:00:00+00:00">March 16, 2026</time></div>';
		$block         = [
			'blockName' => 'core/post-date',
			'attrs'     => [
				'metadata' => [
					'bindings' => [
						'datetime' => [
							'source' => 'core/post-data',
							'args'   => [ 'field' => 'modified' ],
						],
					],
				],
			],
		];

		$result = Post_Date::filter_post_date_block( $block_content, $block );
		$this->assertEmpty( $result, 'Modified date block detected via bindings should be hidden when sitewide is off.' );
	}

	/**
	 * Test render_block detects modified date via displayType attribute.
	 */
	public function test_render_block_detects_modified_via_display_type() {
		set_theme_mod( 'post_updated_date', false );

		$post_id = $this->create_post_with_modified_date(
			gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ),
			gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS )
		);

		global $post;
		$post = get_post( $post_id );

		$block_content = '<div class="wp-block-post-date"><time datetime="2026-03-16T10:00:00+00:00">March 16, 2026</time></div>';
		$block         = [
			'blockName' => 'core/post-date',
			'attrs'     => [ 'displayType' => 'modified' ],
		];

		$result = Post_Date::filter_post_date_block( $block_content, $block );
		$this->assertEmpty( $result, 'Modified date block detected via displayType should be hidden when sitewide is off.' );
	}

	/**
	 * Test modified date output includes data-newspack-modified attribute.
	 */
	public function test_render_block_modified_has_data_attribute() {
		set_theme_mod( 'post_updated_date', true );
		set_theme_mod( 'post_updated_date_threshold', 24 );

		$post_id = $this->create_post_with_modified_date(
			gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ),
			gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS )
		);

		global $post;
		$post = get_post( $post_id );

		// Block without the CSS class (block bindings path).
		$block_content = '<div class="wp-block-post-date"><time datetime="2026-03-15T10:00:00+00:00">March 15, 2026</time></div>';
		$block         = [
			'blockName' => 'core/post-date',
			'attrs'     => [
				'metadata' => [
					'bindings' => [
						'datetime' => [
							'source' => 'core/post-data',
							'args'   => [ 'field' => 'modified' ],
						],
					],
				],
			],
		];

		$result = Post_Date::filter_post_date_block( $block_content, $block );
		$this->assertStringContainsString( 'data-newspack-modified', $result, 'Modified date block should have data-newspack-modified attribute.' );
		$this->assertStringContainsString( 'Updated', $result, 'Modified date block should have Updated label.' );
	}

	/**
	 * Test Updated label appears even when time-ago is disabled.
	 */
	public function test_render_block_updated_label_without_time_ago() {
		set_theme_mod( 'post_time_ago', false );
		set_theme_mod( 'post_updated_date', true );
		set_theme_mod( 'post_updated_date_threshold', 24 );

		$post_id = $this->create_post_with_modified_date(
			gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ),
			gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS )
		);

		global $post;
		$post = get_post( $post_id );

		$block_content = '<div class="wp-block-post-date wp-block-post-date__modified-date"><time datetime="2026-03-15T10:00:00+00:00">March 15, 2026</time></div>';
		$block         = [ 'blockName' => 'core/post-date' ];

		$result = Post_Date::filter_post_date_block( $block_content, $block );
		$this->assertStringContainsString( 'Updated', $result, 'Modified date should have Updated label even without time-ago.' );
		$this->assertStringNotContainsString( 'ago', $result, 'Date should not show time-ago when feature is off.' );
	}

	// ─── Time Ago: Newspack Blocks filter ───

	/**
	 * Test filter_blocks_formatted_date converts date when enabled.
	 */
	public function test_blocks_formatted_date_enabled() {
		set_theme_mod( 'post_time_ago', true );
		set_theme_mod( 'post_time_ago_cut_off', 14 );

		$post_id = static::factory()->post->create(
			[
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ),
			]
		);

		$result = Post_Date::filter_blocks_formatted_date( 'March 17, 2026', get_post( $post_id ) );
		$this->assertStringContainsString( 'ago', $result, 'Blocks formatted date should show relative date when enabled.' );
	}

	/**
	 * Test filter_blocks_formatted_date preserves date when disabled.
	 */
	public function test_blocks_formatted_date_disabled() {
		set_theme_mod( 'post_time_ago', false );

		$post_id = static::factory()->post->create(
			[
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ),
			]
		);

		$result = Post_Date::filter_blocks_formatted_date( 'March 17, 2026', get_post( $post_id ) );
		$this->assertEquals( 'March 17, 2026', $result, 'Blocks formatted date should be unchanged when disabled.' );
	}

	// ─── Theme Switch Migration ───

	/**
	 * Test theme switch copies date settings from old theme to new.
	 */
	public function test_theme_switch_migration() {
		// Simulate old theme having settings.
		$old_theme_slug = 'newspack-theme';
		$old_mods = get_option( "theme_mods_$old_theme_slug", [] );
		$old_mods['post_time_ago'] = true;
		$old_mods['post_time_ago_cut_off'] = 7;
		$old_mods['post_updated_date'] = true;
		$old_mods['post_updated_date_threshold'] = 12;
		update_option( "theme_mods_$old_theme_slug", $old_mods );

		// Create a simple object that implements get_stylesheet().
		$old_theme = new class() {
			/**
			 * Return the old theme stylesheet slug.
			 */
			public function get_stylesheet() {
				return 'newspack-theme';
			}
		};

		Post_Date::migrate_date_settings( 'Newspack', $old_theme );

		$this->assertTrue( get_theme_mod( 'post_time_ago' ), 'post_time_ago should be migrated.' );
		$this->assertEquals( 7, get_theme_mod( 'post_time_ago_cut_off' ), 'post_time_ago_cut_off should be migrated.' );
		$this->assertTrue( get_theme_mod( 'post_updated_date' ), 'post_updated_date should be migrated.' );
		$this->assertEquals( 12, get_theme_mod( 'post_updated_date_threshold' ), 'post_updated_date_threshold should be migrated.' );

		// Cleanup.
		delete_option( "theme_mods_$old_theme_slug" );
	}
}
