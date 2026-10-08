<?php
/**
 * Class Editor Logger Test
 *
 * @package Newspack_Newsletters
 */

use Automattic\WooCommerce\EmailEditor\Email_Editor_Container;
use Automattic\WooCommerce\EmailEditor\Engine\Logger\Default_Email_Editor_Logger;
use Automattic\WooCommerce\EmailEditor\Engine\Logger\Email_Editor_Logger;
use Newspack\Newsletters\Email_Renderers\Editor_Bootstrap;
use Newspack\Newsletters\Email_Renderers\Editor_Logger;

/**
 * Editor Logger Test.
 *
 * The email-editor package logs routine info lines on every request; only its
 * warnings and errors should reach debug.log.
 */
class Test_Editor_Logger extends WP_UnitTestCase {
	/**
	 * Temporary log file.
	 *
	 * @var string
	 */
	private $log_file;

	/**
	 * Boot the editor and create an empty log file.
	 */
	public function set_up() {
		parent::set_up();
		Editor_Bootstrap::init();
		$this->log_file = wp_tempnam( 'editor-logger' );
	}

	/**
	 * Remove the log file.
	 */
	public function tear_down() {
		wp_delete_file( $this->log_file );
		parent::tear_down();
	}

	/**
	 * An Editor_Logger writing to the temporary file, as it would with WP_DEBUG_LOG on.
	 *
	 * @return Editor_Logger
	 */
	private function get_logger() {
		$logger   = new Editor_Logger();
		$property = new ReflectionProperty( Default_Email_Editor_Logger::class, 'log_file' );
		$property->setAccessible( true );
		$property->setValue( $logger, $this->log_file );
		return $logger;
	}

	/**
	 * Contents of the temporary log file.
	 *
	 * @return string
	 */
	private function read_log() {
		return file_get_contents( $this->log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local temp file.
	}

	/**
	 * Routine levels are dropped.
	 */
	public function test_skips_routine_levels() {
		$logger = $this->get_logger();
		$logger->debug( 'debug message' );
		$logger->info( 'Initializing email editor' );
		$logger->notice( 'notice message' );

		$this->assertSame( '', $this->read_log() );
	}

	/**
	 * Warnings and errors are still written.
	 */
	public function test_writes_warnings_and_errors() {
		$logger = $this->get_logger();
		$logger->warning( 'Personalization tag already registered' );
		$logger->error( 'error message' );

		$contents = $this->read_log();
		$this->assertStringContainsString( 'WARNING: Personalization tag already registered', $contents );
		$this->assertStringContainsString( 'ERROR: error message', $contents );
	}

	/**
	 * The package's shared logger delegates to Editor_Logger once the editor is booted.
	 */
	public function test_bootstrap_installs_logger() {
		$wrapper  = Email_Editor_Container::container()->get( Email_Editor_Logger::class );
		$property = new ReflectionProperty( Email_Editor_Logger::class, 'logger' );
		$property->setAccessible( true );

		$this->assertInstanceOf( Editor_Logger::class, $property->getValue( $wrapper ) );
	}
}
