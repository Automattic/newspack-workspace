<?php
/**
 * Tests for the Responsive Container block.
 *
 * @package Newspack\Tests
 * @covers \Newspack\Blocks\Responsive_Container\Responsive_Container_Block
 */

use Newspack\Blocks\Responsive_Container\Responsive_Container_Block;

/**
 * Test class for the Responsive Container Block breakpoint logic.
 *
 * @group responsive-container
 */
class Newspack_Test_Responsive_Container_Block extends WP_UnitTestCase {

	/**
	 * The test-only `wp_theme_json_data_theme` filter, kept so teardown can
	 * remove exactly this callback rather than wiping the whole hook.
	 *
	 * @var callable|null
	 */
	private $theme_json_filter = null;

	/**
	 * Setup.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_clean_theme_json_cache();
		require_once NEWSPACK_ABSPATH . 'src/blocks/responsive-container/class-responsive-container-block.php';
	}

	/**
	 * Tear down: clear filters and the theme.json cache between tests.
	 */
	public function tear_down(): void {
		remove_all_filters( 'newspack_responsive_container_breakpoint' );
		if ( $this->theme_json_filter ) {
			remove_filter( 'wp_theme_json_data_theme', $this->theme_json_filter );
			$this->theme_json_filter = null;
		}
		wp_clean_theme_json_cache();
		parent::tear_down();
	}

	/**
	 * Inject a custom theme.json setting for the breakpoint.
	 *
	 * @param mixed $value Value to set for settings.custom.newspackResponsiveBreakpoint.
	 */
	private function set_theme_json_breakpoint( $value ): void {
		$this->theme_json_filter = function ( $theme_json ) use ( $value ) {
			return $theme_json->update_with(
				[
					'version'  => 2,
					'settings' => [ 'custom' => [ 'newspackResponsiveBreakpoint' => $value ] ],
				]
			);
		};
		add_filter( 'wp_theme_json_data_theme', $this->theme_json_filter );
		wp_clean_theme_json_cache();
	}

	/**
	 * Default breakpoint is 782 when nothing overrides it.
	 */
	public function test_default_breakpoint(): void {
		$this->assertSame( 782, Responsive_Container_Block::get_breakpoint() );
	}

	/**
	 * The filter overrides the default.
	 */
	public function test_filter_overrides_breakpoint(): void {
		add_filter( 'newspack_responsive_container_breakpoint', fn() => 1024 );
		$this->assertSame( 1024, Responsive_Container_Block::get_breakpoint() );
	}

	/**
	 * A numeric theme.json custom setting is used.
	 */
	public function test_theme_json_custom_breakpoint(): void {
		$this->set_theme_json_breakpoint( 900 );
		$this->assertSame( 900, Responsive_Container_Block::get_breakpoint() );
	}

	/**
	 * A non-numeric theme.json custom setting falls back to the default.
	 */
	public function test_non_numeric_custom_falls_back_to_default(): void {
		$this->set_theme_json_breakpoint( 'nope' );
		$this->assertSame( 782, Responsive_Container_Block::get_breakpoint() );
	}

	/**
	 * The filter beats the theme.json custom setting.
	 */
	public function test_filter_beats_theme_json(): void {
		$this->set_theme_json_breakpoint( 900 );
		add_filter( 'newspack_responsive_container_breakpoint', fn() => 600 );
		$this->assertSame( 600, Responsive_Container_Block::get_breakpoint() );
	}

	/**
	 * A non-positive breakpoint (from filter or theme.json) falls back to the default.
	 */
	public function test_non_positive_breakpoint_falls_back_to_default(): void {
		add_filter( 'newspack_responsive_container_breakpoint', fn() => 0 );
		$this->assertSame( 782, Responsive_Container_Block::get_breakpoint() );
	}

	/**
	 * The visibility CSS uses the resolved breakpoint and the breakpoint modifier classes.
	 */
	public function test_visibility_css_uses_breakpoint(): void {
		add_filter( 'newspack_responsive_container_breakpoint', fn() => 800 );
		$css = Responsive_Container_Block::get_visibility_css();
		$this->assertStringContainsString( '(max-width:799px)', $css );
		$this->assertStringContainsString( '(min-width:800px)', $css );
		$this->assertStringContainsString( 'newspack-responsive-container-breakpoint--desktop', $css );
		$this->assertStringContainsString( 'newspack-responsive-container-breakpoint--mobile', $css );
	}
}
