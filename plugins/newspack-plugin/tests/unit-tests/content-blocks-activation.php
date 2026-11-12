<?php
/**
 * Tests the Content Blocks activation step.
 *
 * @package Newspack\Tests
 */

use Newspack\Content_Blocks_Activation;
use Newspack\Plugin_Manager;

/**
 * Test the Content Blocks activation step.
 */
class Newspack_Test_Content_Blocks_Activation extends WP_UnitTestCase {
	const BLOCKS  = 'newspack-blocks/newspack-blocks.php';
	const CONTENT = 'newspack-content-blocks/newspack-content-blocks.php';

	/**
	 * Create a minimal fixture plugin.
	 *
	 * @param string $slug Plugin slug.
	 */
	private function create_plugin( $slug ) {
		$dir = WP_PLUGIN_DIR . '/' . $slug;
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/' . $slug . '.php', "<?php\n/**\n * Plugin Name: Fixture {$slug}\n */\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		wp_clean_plugins_cache();
	}

	/**
	 * Remove a fixture plugin.
	 *
	 * @param string $slug Plugin slug.
	 */
	private function remove_plugin( $slug ) {
		$dir = WP_PLUGIN_DIR . '/' . $slug;
		if ( is_dir( $dir ) ) {
			array_map( 'unlink', glob( $dir . '/*' ) );
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir
		}
	}

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( Content_Blocks_Activation::OPTION );
		update_option( 'active_plugins', [] );
		$this->create_plugin( 'newspack-blocks' );
		$this->create_plugin( 'newspack-content-blocks' );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_all_filters( 'newspack_content_blocks_activation_pre_result' );
		delete_option( Content_Blocks_Activation::OPTION );
		update_option( 'active_plugins', [] );
		$this->remove_plugin( 'newspack-blocks' );
		$this->remove_plugin( 'newspack-content-blocks' );
		wp_clean_plugins_cache();
		parent::tear_down();
	}

	/**
	 * Mark newspack-blocks as active.
	 */
	private function activate_legacy() {
		update_option( 'active_plugins', [ self::BLOCKS ] );
	}

	/**
	 * Skipped when newspack-blocks is not active.
	 */
	public function test_skipped_when_newspack_blocks_inactive() {
		Content_Blocks_Activation::maybe_run();
		$this->assertSame( 'skipped', Content_Blocks_Activation::get_status()['status'] );
		$this->assertFalse( is_plugin_active( self::CONTENT ) );
	}

	/**
	 * Activates an installed plugin.
	 */
	public function test_activates_when_installed() {
		$this->activate_legacy();
		Content_Blocks_Activation::maybe_run();
		$this->assertSame( 'done', Content_Blocks_Activation::get_status()['status'] );
		$this->assertTrue( is_plugin_active( self::CONTENT ) );
	}

	/**
	 * Done when already active, without calling activation.
	 */
	public function test_done_when_already_active() {
		update_option( 'active_plugins', [ self::BLOCKS, self::CONTENT ] );
		add_filter(
			'newspack_content_blocks_activation_pre_result',
			function () {
				return new WP_Error( 'should_not_run', 'Should not run.' );
			}
		);
		Content_Blocks_Activation::maybe_run();
		$this->assertSame( 'done', Content_Blocks_Activation::get_status()['status'] );
	}

	/**
	 * A finished step is not re-run.
	 */
	public function test_done_is_final() {
		$this->activate_legacy();
		Content_Blocks_Activation::maybe_run();
		update_option( 'active_plugins', [ self::BLOCKS ] );
		Content_Blocks_Activation::maybe_run();
		$this->assertFalse( is_plugin_active( self::CONTENT ) );
	}

	/**
	 * Not installable when missing and installs are disallowed.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_not_installable_when_missing_and_installs_disallowed() {
		define( 'DISALLOW_FILE_MODS', true );
		$this->remove_plugin( 'newspack-content-blocks' );
		wp_clean_plugins_cache();
		$this->activate_legacy();
		$this->assertFalse( Plugin_Manager::can_install_plugins() );
		Content_Blocks_Activation::maybe_run();
		$this->assertSame( 'not_installable', Content_Blocks_Activation::get_status()['status'] );
		$this->assertFalse( is_dir( WP_PLUGIN_DIR . '/newspack-content-blocks' ) );
	}

	/**
	 * Failures are recorded and retried with backoff.
	 */
	public function test_failure_retries_with_backoff() {
		$this->activate_legacy();
		add_filter(
			'newspack_content_blocks_activation_pre_result',
			function () {
				return new WP_Error( 'boom', 'Boom.' );
			}
		);

		Content_Blocks_Activation::maybe_run();
		$state = Content_Blocks_Activation::get_status();
		$this->assertSame( 'failed', $state['status'] );
		$this->assertSame( 1, $state['attempts'] );
		$this->assertSame( 'Boom.', $state['error'] );

		// Immediately again: waiting out the delay.
		Content_Blocks_Activation::maybe_run();
		$this->assertSame( 1, Content_Blocks_Activation::get_status()['attempts'] );

		// Two hours later.
		$state['time'] = time() - 2 * HOUR_IN_SECONDS;
		update_option( Content_Blocks_Activation::OPTION, $state );
		Content_Blocks_Activation::maybe_run();
		$this->assertSame( 2, Content_Blocks_Activation::get_status()['attempts'] );
	}

	/**
	 * Backoff schedule.
	 */
	public function test_retry_after_schedule() {
		$this->assertSame( HOUR_IN_SECONDS, Content_Blocks_Activation::retry_after( 1 ) );
		$this->assertSame( 2 * HOUR_IN_SECONDS, Content_Blocks_Activation::retry_after( 2 ) );
		$this->assertSame( DAY_IN_SECONDS, Content_Blocks_Activation::retry_after( 20 ) );
	}

	/**
	 * The attempt is recorded before activation runs.
	 */
	public function test_attempt_recorded_before_activation() {
		$this->activate_legacy();
		$seen = null;
		add_filter(
			'newspack_content_blocks_activation_pre_result',
			function ( $result ) use ( &$seen ) {
				$seen = Content_Blocks_Activation::get_status();
				return $result;
			}
		);
		Content_Blocks_Activation::maybe_run();
		$this->assertSame( 'failed', $seen['status'] );
		$this->assertSame( 1, $seen['attempts'] );
		$this->assertSame( 'Activation did not complete.', $seen['error'] );
		$this->assertSame( 'done', Content_Blocks_Activation::get_status()['status'] );
	}

	/**
	 * Logged-out admin_init does nothing.
	 */
	public function test_admin_init_logged_out_does_nothing() {
		$this->activate_legacy();
		wp_set_current_user( 0 );
		Content_Blocks_Activation::maybe_run_admin();
		$this->assertSame( '', Content_Blocks_Activation::get_status()['status'] );
		$this->assertFalse( is_plugin_active( self::CONTENT ) );
	}

	/**
	 * A user without activate_plugins does nothing.
	 */
	public function test_admin_init_without_capability_does_nothing() {
		$this->activate_legacy();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		Content_Blocks_Activation::maybe_run_admin();
		$this->assertSame( '', Content_Blocks_Activation::get_status()['status'] );
	}

	/**
	 * An administrator triggers the step.
	 */
	public function test_admin_init_as_administrator_runs() {
		$this->activate_legacy();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		Content_Blocks_Activation::maybe_run_admin();
		$this->assertSame( 'done', Content_Blocks_Activation::get_status()['status'] );
	}

	/**
	 * The cron path needs no user.
	 */
	public function test_cron_path_runs_without_user() {
		$this->activate_legacy();
		wp_set_current_user( 0 );
		add_filter( 'wp_doing_cron', '__return_true' );
		do_action( 'wp_loaded' );
		remove_filter( 'wp_doing_cron', '__return_true' );
		$this->assertSame( 'done', Content_Blocks_Activation::get_status()['status'] );
	}

	/**
	 * A WP_Error while the plugin turns out to be active records done, not failed.
	 */
	public function test_error_but_active_records_done() {
		$this->activate_legacy();
		add_filter(
			'newspack_content_blocks_activation_pre_result',
			function () {
				update_option( 'active_plugins', [ self::BLOCKS, self::CONTENT ] );
				return new WP_Error( 'raced', 'Raced.' );
			}
		);
		Content_Blocks_Activation::maybe_run();
		$this->assertSame( 'done', Content_Blocks_Activation::get_status()['status'] );
	}

	/**
	 * Managed and required.
	 */
	public function test_managed_and_required() {
		$this->assertSame(
			'https://newspack.com/downloads/newspack-content-blocks/latest',
			Plugin_Manager::get_managed_plugins()['newspack-content-blocks']['Download']
		);
		$this->assertContains( 'newspack-content-blocks', Plugin_Manager::$required_plugins );
	}
}
