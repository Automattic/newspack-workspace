<?php // phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions -- Tests create and remove a fixture plugin file.
/**
 * Tests for the load guard.
 *
 * @package Newspack_Content_Blocks
 */

use Newspack_Content_Blocks\Guard;
use Newspack_Content_Blocks\Plugin;

/**
 * Guard tests.
 */
class Newspack_Content_Blocks_Test_Guard extends WP_UnitTestCase {
	const LEGACY_PLUGIN = 'newspack-blocks/newspack-blocks.php';

	/**
	 * Fixture file created for a test, if any.
	 *
	 * @var string|null
	 */
	private $fixture_file = null;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		Guard::reset();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		delete_option( 'active_plugins' );
		if ( $this->fixture_file && file_exists( $this->fixture_file ) ) {
			unlink( $this->fixture_file );
			rmdir( dirname( $this->fixture_file ) );
		}
		$this->fixture_file = null;
		Guard::reset();
		parent::tear_down();
	}

	/**
	 * Create a placeholder legacy plugin file under the plugins directory.
	 */
	private function create_legacy_plugin_file() {
		$this->fixture_file = WP_PLUGIN_DIR . '/' . self::LEGACY_PLUGIN;
		if ( ! is_dir( dirname( $this->fixture_file ) ) ) {
			mkdir( dirname( $this->fixture_file ) );
		}
		file_put_contents( $this->fixture_file, "<?php\n" );
	}

	/**
	 * The plugin stays dormant when newspack-blocks is listed as active and present.
	 */
	public function test_dormant_when_legacy_plugin_listed_active() {
		$this->create_legacy_plugin_file();
		update_option( 'active_plugins', [ self::LEGACY_PLUGIN ] );
		$this->assertTrue( Guard::is_legacy_plugin_active() );
	}

	/**
	 * A stale active-plugins entry does not keep the plugin dormant.
	 */
	public function test_live_when_legacy_plugin_listed_but_file_missing() {
		update_option( 'active_plugins', [ self::LEGACY_PLUGIN ] );
		$this->assertFalse( Guard::is_legacy_plugin_active() );
	}

	/**
	 * Nothing active means the plugin is live.
	 */
	public function test_live_when_legacy_plugin_not_listed() {
		update_option( 'active_plugins', [] );
		$this->assertFalse( Guard::is_legacy_plugin_active() );
	}

	/**
	 * The version constant defined by newspack-plugin's bundled copy is not newspack-blocks.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_bundled_copy_constant_does_not_keep_plugin_dormant() {
		require_once __DIR__ . '/fixtures/class-bundled-blocks-stub.php';
		define( 'NEWSPACK_BLOCKS__VERSION', '1.0.0' ); // phpcs:ignore phpcsSniffs.Constants.ConstantDocblock.Missing
		Guard::reset();
		$this->assertFalse( Guard::is_legacy_plugin_active() );
	}

	/**
	 * The version constant without a bundled copy means newspack-blocks loaded.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_legacy_constant_without_bundled_copy_means_active() {
		define( 'NEWSPACK_BLOCKS__VERSION', '1.0.0' ); // phpcs:ignore phpcsSniffs.Constants.ConstantDocblock.Missing
		Guard::reset();
		$this->assertTrue( Guard::is_legacy_plugin_active() );
	}

	/**
	 * Activating newspack-blocks includes its main file in a request where this plugin
	 * is already loaded. That must not fatal.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_legacy_plugin_loads_after_plugin_without_fatal() {
		$this->assertTrue( Plugin::is_loaded() );
		require_once dirname( __DIR__, 2 ) . '/newspack-blocks/newspack-blocks.php';
		$this->assertTrue( class_exists( 'Newspack_Blocks' ) );
		$this->assertTrue( Plugin::is_loaded() );
	}
}
