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
	 * Fixture plugin slugs this test created, and so may remove.
	 *
	 * @var string[]
	 */
	private $created = [];

	/**
	 * Create a minimal fixture plugin, unless a real one is already there.
	 *
	 * The class under test needs the real slugs, so the paths can't be randomised;
	 * nothing is created, and so nothing removed later, where a file already exists.
	 *
	 * @param string $slug Plugin slug.
	 */
	private function create_plugin( $slug ) {
		$dir  = WP_PLUGIN_DIR . '/' . $slug;
		$file = $dir . '/' . $slug . '.php';
		if ( file_exists( $file ) ) {
			$this->markTestSkipped( "A real {$slug} plugin exists at {$file}; refusing to overwrite or remove it." );
		}
		$dir_existed = is_dir( $dir );
		wp_mkdir_p( $dir );
		$this->created[ $slug ] = [
			'file' => $file,
			'dir'  => $dir_existed ? null : $dir,
		];
		file_put_contents( $file, "<?php\n/**\n * Plugin Name: Fixture {$slug}\n */\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		wp_clean_plugins_cache();
	}

	/**
	 * Remove a fixture plugin.
	 *
	 * @param string $slug Plugin slug.
	 */
	private function remove_plugin( $slug ) {
		if ( ! isset( $this->created[ $slug ] ) ) {
			return;
		}
		$fixture = $this->created[ $slug ];
		unset( $this->created[ $slug ] );
		if ( file_exists( $fixture['file'] ) ) {
			unlink( $fixture['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
		}
		if ( $fixture['dir'] && is_dir( $fixture['dir'] ) ) {
			rmdir( $fixture['dir'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir
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
		delete_option( Content_Blocks_Activation::LOCK_OPTION );
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
	 * A fresh lock held by another request blocks the attempt.
	 */
	public function test_fresh_lock_blocks_attempt() {
		$this->activate_legacy();
		add_option( Content_Blocks_Activation::LOCK_OPTION, time(), '', 'no' );
		Content_Blocks_Activation::maybe_run();
		$this->assertSame( '', Content_Blocks_Activation::get_status()['status'] );
		$this->assertFalse( is_plugin_active( self::CONTENT ) );
		$this->assertNotFalse( get_option( Content_Blocks_Activation::LOCK_OPTION ) );
	}

	/**
	 * A stale lock is taken over.
	 */
	public function test_stale_lock_is_taken_over() {
		$this->activate_legacy();
		add_option( Content_Blocks_Activation::LOCK_OPTION, time() - Content_Blocks_Activation::LOCK_TTL - 1, '', 'no' );
		Content_Blocks_Activation::maybe_run();
		$this->assertSame( 'done', Content_Blocks_Activation::get_status()['status'] );
		$this->assertFalse( get_option( Content_Blocks_Activation::LOCK_OPTION ) );
	}

	/**
	 * The lock is released after success and after a WP_Error.
	 */
	public function test_lock_released_after_success_and_error() {
		$this->activate_legacy();
		add_filter(
			'newspack_content_blocks_activation_pre_result',
			function () {
				$this->assertNotFalse( get_option( Content_Blocks_Activation::LOCK_OPTION ) );
				return new WP_Error( 'boom', 'Boom.' );
			}
		);
		Content_Blocks_Activation::maybe_run();
		$this->assertFalse( get_option( Content_Blocks_Activation::LOCK_OPTION ) );

		remove_all_filters( 'newspack_content_blocks_activation_pre_result' );
		$state         = Content_Blocks_Activation::get_status();
		$state['time'] = time() - 2 * HOUR_IN_SECONDS;
		update_option( Content_Blocks_Activation::OPTION, $state );
		Content_Blocks_Activation::maybe_run();
		$this->assertSame( 'done', Content_Blocks_Activation::get_status()['status'] );
		$this->assertFalse( get_option( Content_Blocks_Activation::LOCK_OPTION ) );
	}

	/**
	 * The admin path does no plugin scan once the step is final.
	 */
	public function test_admin_path_skips_plugin_scan_when_done() {
		update_option( Content_Blocks_Activation::OPTION, [ 'status' => 'done', 'time' => time(), 'attempts' => 0, 'error' => '' ] ); // phpcs:ignore
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		wp_clean_plugins_cache(); // get_plugins() repopulates this cache when it scans.
		Content_Blocks_Activation::maybe_run_admin();
		$this->assertFalse( wp_cache_get( 'plugins', 'plugins' ) );
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
