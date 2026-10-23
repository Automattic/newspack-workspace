<?php
/**
 * Class Newsletters Test autoloading without the main plugin file.
 *
 * @package Newspack_Newsletters
 */

/**
 * Loading a class file through the classmap must not boot the plugin.
 *
 * @group autoload
 */
class Newsletters_Autoload_Without_Main_File_Test extends WP_UnitTestCase {

	/**
	 * Autoloading the main class without the main file neither fatals on init nor boots the plugin.
	 */
	public function test_autoload_without_main_file_does_not_boot() {
		$script  = __DIR__ . '/fixtures/load-classes-without-main-file.php';
		$command = 'WP_TESTS_SKIP_INSTALL=1 ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' 2>&1';
		exec( $command, $output, $exit_code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		$output = implode( PHP_EOL, $output );

		$this->assertStringNotContainsString( 'NEWSPACK_NEWSLETTERS_PLUGIN_FILE', $output );
		$this->assertSame( 0, $exit_code, $output );
		$this->assertStringContainsString( 'init-hooked:no', $output );
	}
}
